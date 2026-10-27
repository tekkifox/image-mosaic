package main

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
)

// withConfig swaps the package-level config for the duration of a test.
func withConfig(t *testing.T, c Config) {
	t.Helper()
	previous := cfg
	cfg = c
	t.Cleanup(func() { cfg = previous })
}

// fakePhotoPrism serves the subset of the PhotoPrism API the handlers use.
// Tests point cfg.PhotoPrismBaseURL at it, so no external service is required.
func fakePhotoPrism(t *testing.T, photos []map[string]any) *httptest.Server {
	t.Helper()

	list := func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")

		q := r.URL.Query().Get("q")
		if q != "" &&
			!strings.Contains(q, `category:"Travelling"`) &&
			!strings.Contains(q, `path:"Travelling"`) {
			_ = json.NewEncoder(w).Encode([]map[string]any{})
			return
		}

		count := len(photos)
		if raw := r.URL.Query().Get("count"); raw != "" {
			if n, err := strconv.Atoi(raw); err == nil && n > 0 && n < count {
				count = n
			}
		}
		_ = json.NewEncoder(w).Encode(photos[:count])
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/api/v1/photos/view", list)
	mux.HandleFunc("/api/v1/photos", list)

	server := httptest.NewServer(mux)
	t.Cleanup(server.Close)
	return server
}

func photoFixture(i int) map[string]any {
	return map[string]any{
		"UID":     fmt.Sprintf("uid%02d", i),
		"Hash":    fmt.Sprintf("hash%02d", i),
		"Title":   fmt.Sprintf("Photo %02d", i),
		"TakenAt": "2016-03-04",
		"Caption": fmt.Sprintf("Caption %d", i),
		"Path":    "travel/Travelling/japan.jpg",
		// Albums is present so buildTiles does not try to fetch photo details.
		"Albums": []any{map[string]any{"Title": "Japan"}},
		"Thumbs": map[string]any{
			"tile_224": map[string]any{"src": fmt.Sprintf("/thumb/%02d.jpg", i)},
			"fit_720":  map[string]any{"src": fmt.Sprintf("/medium/%02d.jpg", i)},
			"fit_1280": map[string]any{"src": fmt.Sprintf("/full/%02d.jpg", i)},
		},
	}
}

func photoFixtures(n int) []map[string]any {
	photos := make([]map[string]any, n)
	for i := range photos {
		photos[i] = photoFixture(i + 1)
	}
	return photos
}

func perform(handler http.HandlerFunc, target string) *httptest.ResponseRecorder {
	request := httptest.NewRequest(http.MethodGet, target, nil)
	recorder := httptest.NewRecorder()
	handler(recorder, request)
	return recorder
}

type tileJSON struct {
	Title     string   `json:"title"`
	Albums    []string `json:"albums"`
	ThumbURL  string   `json:"thumbUrl"`
	MediumURL string   `json:"mediumUrl"`
	FullURL   string   `json:"fullUrl"`
	ImageHash string   `json:"imageHash"`
	Taken     string   `json:"taken"`
	Caption   string   `json:"caption"`
}

type tilesJSON struct {
	Columns int        `json:"columns"`
	Rows    int        `json:"rows"`
	Tiles   []tileJSON `json:"tiles"`
	Total   int        `json:"total"`
	Offset  int        `json:"offset"`
	Limit   int        `json:"limit"`
	HasMore bool       `json:"hasMore"`
}

func decodeTiles(t *testing.T, body []byte) tilesJSON {
	t.Helper()
	var payload tilesJSON
	if err := json.Unmarshal(body, &payload); err != nil {
		t.Fatalf("decoding tiles response: %v\nbody: %s", err, body)
	}
	return payload
}

func TestTilesHandler(t *testing.T) {
	tests := []struct {
		name       string
		target     string
		photos     []map[string]any
		wantStatus int
		check      func(t *testing.T, response *httptest.ResponseRecorder, payload tilesJSON)
	}{
		{
			name:       "returns the requested window and pagination metadata",
			target:     "/api/tiles?category=Travelling&limit=2&offset=1",
			photos:     photoFixtures(5),
			wantStatus: http.StatusOK,
			check: func(t *testing.T, response *httptest.ResponseRecorder, payload tilesJSON) {
				t.Helper()

				if got, want := response.Header().Get("Content-Type"), "application/json; charset=utf-8"; got != want {
					t.Errorf("Content-Type = %q, want %q", got, want)
				}
				if got, want := response.Header().Get("Cache-Control"), "public, max-age=30"; got != want {
					t.Errorf("Cache-Control = %q, want %q", got, want)
				}
				if response.Header().Get("ETag") == "" {
					t.Error("ETag header is empty")
				}

				if payload.Columns != 12 || payload.Rows != 12 {
					t.Errorf("grid = %dx%d, want 12x12", payload.Columns, payload.Rows)
				}
				if payload.Offset != 1 || payload.Limit != 2 {
					t.Errorf("offset/limit = %d/%d, want 1/2", payload.Offset, payload.Limit)
				}
				if !payload.HasMore {
					t.Error("hasMore = false, want true")
				}
				if payload.Total != 5 {
					t.Errorf("total = %d, want 5", payload.Total)
				}
				if len(payload.Tiles) != 2 {
					t.Fatalf("tiles = %d, want 2", len(payload.Tiles))
				}

				first := payload.Tiles[0]
				if first.Title != "Photo 02" {
					t.Errorf("first title = %q, want Photo 02", first.Title)
				}
				if len(first.Albums) != 1 || first.Albums[0] != "Japan" {
					t.Errorf("first albums = %v, want [Japan]", first.Albums)
				}
				if first.ImageHash != "hash02" || first.Taken != "2016-03-04" || first.Caption != "Caption 2" {
					t.Errorf("unexpected tile metadata: %+v", first)
				}
				if !strings.HasSuffix(first.ThumbURL, "/thumb/02.jpg") {
					t.Errorf("thumbUrl = %q, want a /thumb/02.jpg suffix", first.ThumbURL)
				}
			},
		},
		{
			name:       "requires a category",
			target:     "/api/tiles?limit=2",
			photos:     photoFixtures(2),
			wantStatus: http.StatusBadRequest,
			check: func(t *testing.T, response *httptest.ResponseRecorder, _ tilesJSON) {
				t.Helper()
				var payload map[string]any
				if err := json.Unmarshal(response.Body.Bytes(), &payload); err != nil {
					t.Fatalf("decoding error response: %v", err)
				}
				if payload["error"] != "category required" {
					t.Errorf("error = %v, want \"category required\"", payload["error"])
				}
			},
		},
		{
			name:       "ignores invalid limit and offset values",
			target:     "/api/tiles?category=Travelling&limit=abc&offset=-3",
			photos:     photoFixtures(5),
			wantStatus: http.StatusOK,
			check: func(t *testing.T, _ *httptest.ResponseRecorder, payload tilesJSON) {
				t.Helper()
				if payload.Limit != 36 {
					t.Errorf("limit = %d, want the default 36", payload.Limit)
				}
				if payload.Offset != 0 {
					t.Errorf("offset = %d, want the default 0", payload.Offset)
				}
				if len(payload.Tiles) != 5 {
					t.Errorf("tiles = %d, want all 5", len(payload.Tiles))
				}
				if payload.HasMore {
					t.Error("hasMore = true, want false")
				}
			},
		},
		{
			name:       "returns placeholder slots when the category has no photos",
			target:     "/api/tiles?category=Travelling&limit=18",
			photos:     nil,
			wantStatus: http.StatusOK,
			check: func(t *testing.T, _ *httptest.ResponseRecorder, payload tilesJSON) {
				t.Helper()
				if len(payload.Tiles) != 36 {
					t.Fatalf("tiles = %d, want 36 placeholder slots", len(payload.Tiles))
				}
				if payload.Tiles[0].Title != "Empty slot" {
					t.Errorf("placeholder title = %q, want Empty slot", payload.Tiles[0].Title)
				}
				if payload.Total != 0 || payload.HasMore {
					t.Errorf("total/hasMore = %d/%v, want 0/false", payload.Total, payload.HasMore)
				}
			},
		},
		{
			name:       "still returns placeholder slots when PhotoPrism is unavailable",
			target:     "/api/tiles?category=Travelling&limit=18",
			photos:     nil,
			wantStatus: http.StatusOK,
			check: func(t *testing.T, _ *httptest.ResponseRecorder, payload tilesJSON) {
				t.Helper()
				if len(payload.Tiles) != 36 {
					t.Errorf("tiles = %d, want 36 placeholder slots", len(payload.Tiles))
				}
				if payload.Total != 0 {
					t.Errorf("total = %d, want 0", payload.Total)
				}
			},
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			server := fakePhotoPrism(t, tc.photos)
			if tc.name == "still returns placeholder slots when PhotoPrism is unavailable" {
				server.Close()
			}
			withConfig(t, Config{PhotoPrismBaseURL: server.URL})

			response := perform(tilesHandler, tc.target)

			if response.Code != tc.wantStatus {
				t.Fatalf("status = %d, want %d\nbody: %s", response.Code, tc.wantStatus, response.Body.String())
			}
			tc.check(t, response, decodeTiles(t, response.Body.Bytes()))
		})
	}
}

func TestTilesHandlerPrefersTokenizedFullSizeURLs(t *testing.T) {
	server := fakePhotoPrism(t, photoFixtures(1))
	withConfig(t, Config{
		PhotoPrismBaseURL: server.URL,
		PhotoPrismToken:   "secret-token",
	})

	response := perform(tilesHandler, "/api/tiles?category=Travelling&limit=1")
	if response.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", response.Code)
	}

	payload := decodeTiles(t, response.Body.Bytes())
	if len(payload.Tiles) != 1 {
		t.Fatalf("tiles = %d, want 1", len(payload.Tiles))
	}
	tile := payload.Tiles[0]
	if !strings.HasSuffix(tile.ThumbURL, "/thumb/01.jpg") {
		t.Errorf("thumbUrl = %q, want the viewer-provided thumbnail", tile.ThumbURL)
	}
	for name, value := range map[string]string{
		"mediumUrl": tile.MediumURL,
		"fullUrl":   tile.FullURL,
	} {
		if !strings.HasPrefix(value, server.URL+"/api/v1/t/") || !strings.Contains(value, "secret-token") {
			t.Errorf("%s = %q, want a tokenized PhotoPrism URL", name, value)
		}
	}
}

func TestPhotoCountHandler(t *testing.T) {
	tests := []struct {
		name        string
		target      string
		photos      []map[string]any
		unavailable bool
		wantCount   int
	}{
		{
			name:      "counts a category",
			target:    "/api/photo-count?category=Travelling",
			photos:    photoFixtures(5),
			wantCount: 5,
		},
		{
			name:      "counts everything when category is omitted",
			target:    "/api/photo-count",
			photos:    photoFixtures(3),
			wantCount: 3,
		},
		{
			name:      "returns zero for an unknown category",
			target:    "/api/photo-count?category=Nowhere",
			photos:    photoFixtures(4),
			wantCount: 0,
		},
		{
			name:        "returns zero when PhotoPrism is unavailable",
			target:      "/api/photo-count?category=Travelling",
			photos:      photoFixtures(4),
			unavailable: true,
			wantCount:   0,
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			server := fakePhotoPrism(t, tc.photos)
			if tc.unavailable {
				server.Close()
			}
			withConfig(t, Config{PhotoPrismBaseURL: server.URL})

			response := perform(photoCountHandler, tc.target)
			if response.Code != http.StatusOK {
				t.Fatalf("status = %d, want 200", response.Code)
			}
			if got, want := response.Header().Get("Content-Type"), "application/json; charset=utf-8"; got != want {
				t.Errorf("Content-Type = %q, want %q", got, want)
			}

			var payload struct {
				Count int `json:"count"`
			}
			if err := json.Unmarshal(response.Body.Bytes(), &payload); err != nil {
				t.Fatalf("decoding response: %v", err)
			}
			if payload.Count != tc.wantCount {
				t.Errorf("count = %d, want %d", payload.Count, tc.wantCount)
			}
		})
	}
}

func TestSecurityHeaders(t *testing.T) {
	handler := securityHeaders(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	}))

	t.Run("page responses get the strict policy", func(t *testing.T) {
		response := httptest.NewRecorder()
		handler.ServeHTTP(response, httptest.NewRequest(http.MethodGet, "/", nil))

		if got := response.Header().Get("Content-Security-Policy"); !strings.Contains(got, "script-src 'self'") {
			t.Errorf("CSP = %q, want script-src 'self'", got)
		}
		if got := response.Header().Get("X-Content-Type-Options"); got != "nosniff" {
			t.Errorf("X-Content-Type-Options = %q, want nosniff", got)
		}
		if got := response.Header().Get("X-Frame-Options"); got != "DENY" {
			t.Errorf("X-Frame-Options = %q, want DENY", got)
		}
		if got := response.Header().Get("Referrer-Policy"); got != "strict-origin-when-cross-origin" {
			t.Errorf("Referrer-Policy = %q", got)
		}
	})

	t.Run("swagger gets its own relaxed policy", func(t *testing.T) {
		response := httptest.NewRecorder()
		handler.ServeHTTP(response, httptest.NewRequest(http.MethodGet, "/swagger/index.html", nil))

		got := response.Header().Get("Content-Security-Policy")
		if !strings.Contains(got, "'unsafe-inline'") || !strings.Contains(got, "'unsafe-eval'") {
			t.Errorf("swagger CSP = %q, want the relaxed policy", got)
		}
	})
}

func TestNormalizePhotoPrismBaseURL(t *testing.T) {
	tests := []struct {
		input string
		want  string
	}{
		{"https://photos.example.com", "https://photos.example.com"},
		{"https://photos.example.com/", "https://photos.example.com"},
		{"https://photos.example.com/api", "https://photos.example.com"},
		{"https://photos.example.com/api/v1/", "https://photos.example.com"},
		{"  https://photos.example.com//  ", "https://photos.example.com"},
		{"", ""},
	}

	for _, tc := range tests {
		t.Run(tc.input, func(t *testing.T) {
			if got := normalizePhotoPrismBaseURL(tc.input); got != tc.want {
				t.Errorf("normalizePhotoPrismBaseURL(%q) = %q, want %q", tc.input, got, tc.want)
			}
		})
	}
}

func TestBuildTilesURLStrategy(t *testing.T) {
	basePhoto := func() map[string]any {
		return map[string]any{
			"UID":    "uid1",
			"Hash":   "hash1",
			"Title":  "Photo",
			"Albums": []any{"Japan"},
			"Thumbs": map[string]any{
				"tile_224": map[string]any{"src": "/thumb.jpg"},
				"fit_720":  map[string]any{"src": "/medium.jpg"},
				"fit_1280": map[string]any{"src": "/full.jpg"},
			},
		}
	}

	tests := []struct {
		name       string
		config     Config
		mutate     func(map[string]any)
		wantThumb  string
		wantMedium string
		wantFull   string
	}{
		{
			name:       "uses viewer thumbnails when no token is configured",
			config:     Config{PhotoPrismBaseURL: "https://pp.example"},
			wantThumb:  "https://pp.example/thumb.jpg",
			wantMedium: "https://pp.example/medium.jpg",
			wantFull:   "https://pp.example/full.jpg",
		},
		{
			name:       "prefers tokenized sizes when an access token is configured",
			config:     Config{PhotoPrismBaseURL: "https://pp.example", PhotoPrismToken: "tok"},
			wantThumb:  "https://pp.example/thumb.jpg",
			wantMedium: "https://pp.example/api/v1/t/hash1/tok/fit_720",
			wantFull:   "https://pp.example/api/v1/t/hash1/tok/fit_1280",
		},
		{
			name:       "falls back to download URLs without thumbnails or a token",
			config:     Config{PhotoPrismBaseURL: "https://pp.example"},
			mutate:     func(photo map[string]any) { delete(photo, "Thumbs") },
			wantThumb:  "https://pp.example/api/v1/photos/uid1/dl",
			wantMedium: "https://pp.example/api/v1/photos/uid1/dl",
			wantFull:   "https://pp.example/api/v1/photos/uid1/dl",
		},
		{
			name:       "builds tokenized URLs from the hash when thumbnails are missing",
			config:     Config{PhotoPrismBaseURL: "https://pp.example", PhotoPrismToken: "tok"},
			mutate:     func(photo map[string]any) { delete(photo, "Thumbs") },
			wantThumb:  "https://pp.example/api/v1/t/hash1/tok/tile_224",
			wantMedium: "https://pp.example/api/v1/t/hash1/tok/fit_720",
			wantFull:   "https://pp.example/api/v1/t/hash1/tok/fit_1280",
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			photo := basePhoto()
			if tc.mutate != nil {
				tc.mutate(photo)
			}
			withConfig(t, tc.config)

			tiles := buildTiles([]map[string]any{photo})
			if len(tiles) != 1 {
				t.Fatalf("tiles = %d, want 1", len(tiles))
			}
			if got := tiles[0]["thumbUrl"]; got != tc.wantThumb {
				t.Errorf("thumbUrl = %v, want %s", got, tc.wantThumb)
			}
			if got := tiles[0]["mediumUrl"]; got != tc.wantMedium {
				t.Errorf("mediumUrl = %v, want %s", got, tc.wantMedium)
			}
			if got := tiles[0]["fullUrl"]; got != tc.wantFull {
				t.Errorf("fullUrl = %v, want %s", got, tc.wantFull)
			}
		})
	}
}
