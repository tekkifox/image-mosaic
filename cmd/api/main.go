package main

import (
	"embed"
	"encoding/json"
	"fmt"
	httpSwagger "github.com/swaggo/http-swagger"
	docs "github.com/tekkifox/image-mosaic/docs"
	"io"
	"io/fs"
	"log"
	"net/http"
	"net/url"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"
)

//go:embed public/** docs/**
var embeddedFiles embed.FS

// computeCountryPlaces aggregates photos by country and place names.
func computeCountryPlaces(photos []map[string]any, category string) []map[string]any {
	// Helper functions
	extractStringValue := func(source map[string]any, keys []string) string {
		for _, k := range keys {
			if v, ok := source[k]; ok {
				switch t := v.(type) {
				case string:
					s := strings.TrimSpace(t)
					if s != "" {
						return s
					}
				case float64, int, int64:
					return fmt.Sprintf("%v", t)
				}
			}
		}
		return ""
	}

	normalizePlaceText := func(text string) string {
		s := strings.TrimSpace(text)
		if s == "" {
			return ""
		}
		s = strings.Join(strings.Fields(s), " ")
		return s
	}

	stripCountryFromPlace := func(label, country string) string {
		if label == "" || country == "" {
			return label
		}
		placeLower := strings.ToLower(label)
		countryLower := strings.ToLower(country)
		suffixes := []string{", " + countryLower, " - " + countryLower, " " + countryLower}
		for _, suf := range suffixes {
			if strings.HasSuffix(placeLower, suf) {
				// drop suffix from original label
				trimmed := strings.TrimSpace(label[:len(label)-len(suf)])
				if trimmed != "" {
					return trimmed
				}
			}
		}
		return label
	}

	countryToFullName := func(country string) string {
		if country == "" {
			return country
		}
		c := strings.TrimSpace(country)
		if len(c) == 2 {
			return strings.ToUpper(c)
		}
		return normalizePlaceText(c)
	}

	excluded := map[string]bool{"hungary": true, "france": true, "unknown region": true}

	type placeInfo struct {
		name  string
		count int
	}
	type countryInfo struct {
		country    string
		photoCount int
		places     map[string]int
		placeNames map[string]string
	}

	countriesMap := map[string]*countryInfo{}

	for _, photo := range photos {
		// gather possible place nodes
		sources := []map[string]any{photo}
		for _, nested := range []string{"Place", "place", "Location", "location"} {
			if v, ok := photo[nested]; ok {
				if m, ok2 := v.(map[string]any); ok2 {
					sources = append(sources, m)
				}
			}
		}

		var foundPlace, foundCountry string
		for _, src := range sources {
			label := extractStringValue(src, []string{"Label", "label", "PlaceLabel", "placeLabel"})
			if label == "" {
				// try composed city/state/country
				city := extractStringValue(src, []string{"City", "city", "PlaceCity", "placeCity"})
				state := extractStringValue(src, []string{"State", "state", "Region", "region", "PlaceState", "placeState"})
				country := extractStringValue(src, []string{"Country", "country", "PlaceCountry", "placeCountry"})
				parts := []string{}
				if city != "" {
					parts = append(parts, city)
				}
				if state != "" {
					parts = append(parts, state)
				}
				if country != "" {
					parts = append(parts, country)
				}
				if len(parts) > 0 {
					label = strings.Join(parts, ", ")
				}
			}

			country := extractStringValue(src, []string{"Country", "country", "PlaceCountry", "placeCountry"})
			if country == "" && label != "" && strings.Contains(label, ",") {
				// last segment may be country
				parts := strings.Split(label, ",")
				last := strings.TrimSpace(parts[len(parts)-1])
				if last != "" {
					country = last
				}
			}

			if label != "" && country != "" {
				foundPlace = normalizePlaceText(label)
				foundCountry = countryToFullName(country)
				break
			}
		}

		if foundCountry == "" || foundPlace == "" {
			continue
		}

		ck := strings.ToLower(foundCountry)
		if excluded[ck] {
			continue
		}

		ci, ok := countriesMap[ck]
		if !ok {
			ci = &countryInfo{country: foundCountry, photoCount: 0, places: map[string]int{}, placeNames: map[string]string{}}
			countriesMap[ck] = ci
		}
		ci.photoCount++
		placeKey := strings.ToLower(foundPlace)
		ci.places[placeKey] = ci.places[placeKey] + 1
		if _, exists := ci.placeNames[placeKey]; !exists {
			ci.placeNames[placeKey] = foundPlace
		}
	}

	// Build list
	var countryList []map[string]any
	for _, ci := range countriesMap {
		// convert places map to sorted slice
		var placeSlice []placeInfo
		for name, cnt := range ci.places {
			display := ci.placeNames[name]
			if display == "" {
				display = name
			}
			// strip country suffix if present
			display = stripCountryFromPlace(display, ci.country)
			placeSlice = append(placeSlice, placeInfo{name: display, count: cnt})
		}
		// sort by count desc then name
		sort.Slice(placeSlice, func(i, j int) bool {
			if placeSlice[i].count == placeSlice[j].count {
				return placeSlice[i].name < placeSlice[j].name
			}
			return placeSlice[i].count > placeSlice[j].count
		})
		top := []map[string]any{}
		for i, p := range placeSlice {
			if i >= 6 {
				break
			}
			top = append(top, map[string]any{"name": p.name, "count": p.count})
		}

		countryList = append(countryList, map[string]any{
			"country":    ci.country,
			"photoCount": ci.photoCount,
			"places":     top,
		})
	}

	// sort countryList
	sort.Slice(countryList, func(i, j int) bool {
		ai := countryList[i]["photoCount"].(int)
		aj := countryList[j]["photoCount"].(int)
		if ai == aj {
			return countryList[i]["country"].(string) < countryList[j]["country"].(string)
		}
		return ai > aj
	})

	return countryList
}

type Config struct {
	PhotoPrismBaseURL string
	PhotoPrismToken   string
	PhotoPrismAPIKey  string
}

var cfg Config
var lastRequest any

func main() {
	cfg = Config{
		PhotoPrismBaseURL: getenv("PHOTO_PRISM_BASE_URL", "https://photoprism.example.com"),
		PhotoPrismToken:   getenv("PHOTO_PRISM_ACCESS_TOKEN", ""),
		PhotoPrismAPIKey:  getenv("PHOTO_PRISM_API_KEY", ""),
	}

	// REST API handlers
	http.HandleFunc("/api/debug", debugHandler)
	http.HandleFunc("/api/albums", albumsHandler)
	http.HandleFunc("/api/tiles", tilesHandler)
	http.HandleFunc("/api/photo-count", photoCountHandler)
	http.HandleFunc("/api/country-places", countryPlacesHandler)
	http.HandleFunc("/api/featured", featuredHandler)

	// Register generated swagger docs for http-swagger UI
	docs.SwaggerInfo.Title = "Image Mosaic API"
	docs.SwaggerInfo.Version = "1.0"
	docs.SwaggerInfo.Host = "localhost:8080"
	docs.SwaggerInfo.BasePath = "/"
	docs.SwaggerInfo.Schemes = []string{"http"}

	// Serve swagger UI via http-swagger
	http.Handle("/swagger/", httpSwagger.WrapHandler)
	// Serve OpenAPI JSON at /swagger/doc.json (embedded)
	docsFS, _ := fs.Sub(embeddedFiles, "docs")
	http.Handle("/swagger/doc.json", http.FileServer(http.FS(docsFS)))

	// Serve static assets from embedded public/ directory
	publicFS, _ := fs.Sub(embeddedFiles, "public")
	http.Handle("/", http.FileServer(http.FS(publicFS)))

    port := getenv("PORT", "8080")
    addr := ":" + port
    log.Printf("Starting Go server on %s (serving ./public and /api/*)", addr)
    log.Fatal(http.ListenAndServe(addr, nil))
}

// legacy apiHandler removed — REST endpoints implemented below

func getenv(k, def string) string {
	v := os.Getenv(k)
	if v == "" {
		return def
	}
	return v
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	enc := json.NewEncoder(w)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(v)
}

// debugHandler returns connection status and last request.
func debugHandler(w http.ResponseWriter, r *http.Request) {
	resp := map[string]any{
		"connection": map[string]any{
			"baseUrl":        cfg.PhotoPrismBaseURL,
			"hasAccessToken": cfg.PhotoPrismToken != "",
			"hasApiKey":      cfg.PhotoPrismAPIKey != "",
			"authType": func() string {
				if cfg.PhotoPrismToken != "" {
					return "access_token"
				}
				if cfg.PhotoPrismAPIKey != "" {
					return "api_key"
				}
				return "none"
			}(),
		},
		"lastRequest": lastRequest,
	}
	writeJSON(w, http.StatusOK, resp)
}

// albumsHandler returns albums for a category. Query: ?category=Name
func albumsHandler(w http.ResponseWriter, r *http.Request) {
	category := r.URL.Query().Get("category")
	if category == "" {
		writeJSON(w, http.StatusBadRequest, map[string]any{"error": "category required"})
		return
	}
	albums, err := photosRequest("/api/v1/albums", map[string]string{"category": category, "count": "100", "order": "newest"})
	if err != nil {
		// fallback
		derived, derr := deriveAlbumsFromPhotos(category)
		if derr != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{"error": err.Error()})
			return
		}
		writeJSON(w, http.StatusOK, map[string]any{"albums": derived})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"albums": albums})
}

// tilesHandler returns tiles. Query: ?category=Name&limit=18&offset=0
func tilesHandler(w http.ResponseWriter, r *http.Request) {
	category := r.URL.Query().Get("category")
	if category == "" {
		writeJSON(w, http.StatusBadRequest, map[string]any{"error": "category required"})
		return
	}
	limit := 36
	if v := r.URL.Query().Get("limit"); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 {
			limit = n
		}
	}
	offset := 0
	if v := r.URL.Query().Get("offset"); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n >= 0 {
			offset = n
		}
	}

	// Ask Photoprism for enough photos to satisfy offset+limit
	fetchCount := offset + limit
	photos, err := listPhotos(fetchCount, "", category, "random")
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"error": err.Error()})
		return
	}
	// slice
	if offset > len(photos) {
		photos = []map[string]any{}
	} else {
		end := offset + limit
		if end > len(photos) {
			end = len(photos)
		}
		photos = photos[offset:end]
	}
	tiles := buildTiles(photos)
	total := getPhotoCount("", category)
	writeJSON(w, http.StatusOK, map[string]any{
		"columns": 12,
		"rows":    12,
		"tiles":   tiles,
		"total":   total,
		"offset":  offset,
		"limit":   limit,
		"hasMore": (offset+len(tiles) < total),
	})
}

// photoCountHandler returns count for category
func photoCountHandler(w http.ResponseWriter, r *http.Request) {
	category := r.URL.Query().Get("category")
	count := getPhotoCount("", category)
	writeJSON(w, http.StatusOK, map[string]any{"count": count})
}

// countryPlacesHandler computes country->places aggregation
func countryPlacesHandler(w http.ResponseWriter, r *http.Request) {
	category := r.URL.Query().Get("category")
	photos, err := listPhotos(1000, "", category, "random")
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"error": err.Error()})
		return
	}
	countries := computeCountryPlaces(photos, category)
	writeJSON(w, http.StatusOK, map[string]any{"countries": countries})
}

// featuredHandler returns a single featured photo
func featuredHandler(w http.ResponseWriter, r *http.Request) {
	category := r.URL.Query().Get("category")
	photos, err := listPhotos(1, "", category, "newest")
	if err != nil || len(photos) == 0 {
		writeJSON(w, http.StatusNotFound, map[string]any{"error": "No photos found"})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"photo": photos[0]})
}

func photosRequest(path string, params map[string]string) ([]map[string]any, error) {
	u, err := url.Parse(cfg.PhotoPrismBaseURL)
	if err != nil {
		return nil, err
	}
	u.Path = strings.TrimRight(u.Path, "/") + path
	q := u.Query()
	for k, v := range params {
		q.Set(k, v)
	}
	u.RawQuery = q.Encode()

	req, _ := http.NewRequest("GET", u.String(), nil)
	if cfg.PhotoPrismToken != "" {
		req.Header.Set("Authorization", "Bearer "+cfg.PhotoPrismToken)
		req.Header.Set("X-Auth-Token", cfg.PhotoPrismToken)
	}
	if cfg.PhotoPrismAPIKey != "" {
		req.Header.Set("X-API-Key", cfg.PhotoPrismAPIKey)
	}

	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Do(req)
	lastRequest = map[string]any{"url": u.String(), "method": "GET"}
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	if resp.StatusCode >= 400 {
		return nil, fmt.Errorf("PhotoPrism API request failed (%d): %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}
	var out []map[string]any
	if len(body) == 0 {
		return out, nil
	}
	if err := json.Unmarshal(body, &out); err != nil {
		// sometimes API returns object, try to decode to map
		var obj any
		if err2 := json.Unmarshal(body, &obj); err2 == nil {
			if m, ok := obj.([]any); ok {
				for _, it := range m {
					if mm, ok := it.(map[string]any); ok {
						out = append(out, mm)
					}
				}
			}
		}
	}
	return out, nil
}

func listPhotos(limit int, album, category, order string) ([]map[string]any, error) {
	params := map[string]string{"count": strconv.Itoa(limit), "order": order}
	if album != "" {
		// attempt to find album UID is omitted; use search by album title
		params["q"] = fmt.Sprintf("albums:\"%s\"", album)
	}
	if category != "" {
		params["q"] = fmt.Sprintf("category:\"%s\"", category)
	}
	photos, err := photosRequest("/api/v1/photos", params)
	if err != nil {
		return nil, err
	}
	return photos, nil
}

func getPhotoCount(album, category string) int {
	params := map[string]string{"count": "10000", "order": "random"}
	if category != "" {
		params["q"] = fmt.Sprintf("category:\"%s\"", category)
	}
	photos, err := photosRequest("/api/v1/photos", params)
	if err != nil {
		return 0
	}
	return len(photos)
}

func buildTiles(photos []map[string]any) []map[string]any {
	var tiles []map[string]any
	preview := ""
	// try to fetch preview token by calling session endpoint
	preview = fetchPreviewToken()
	for _, p := range photos {
		title := toString(p["Title"])
		if title == "" {
			title = toString(p["title"])
		}
		hash := toString(p["Hash"])
		if hash == "" {
			hash = toString(p["hash"])
		}
		thumb := ""
		if hash != "" && preview != "" {
			thumb = fmt.Sprintf("%s/api/v1/t/%s/%s/%s", strings.TrimRight(cfg.PhotoPrismBaseURL, "/"), url.PathEscape(hash), url.PathEscape(preview), "tile_224")
		}
		tiles = append(tiles, map[string]any{
			"title":      title,
			"albums":     []string{},
			"thumbUrl":   thumb,
			"mediumUrl":  thumb,
			"fullUrl":    thumb,
			"imageHash":  hash,
			"mediumHash": hash,
			"taken":      toString(p["TakenAt"]),
			"caption":    toString(p["Caption"]),
		})
	}
	if len(tiles) == 0 {
		for i := 0; i < 36; i++ {
			tiles = append(tiles, map[string]any{"title": "Empty slot", "albums": []string{}, "thumbUrl": "", "mediumUrl": "", "fullUrl": "", "imageHash": "", "mediumHash": "", "taken": "", "caption": ""})
		}
	}
	return tiles
}

func toString(v any) string {
	if v == nil {
		return ""
	}
	switch t := v.(type) {
	case string:
		return t
	case fmt.Stringer:
		return t.String()
	default:
		return fmt.Sprintf("%v", v)
	}
}

func fetchPreviewToken() string {
	// Try to get a preview token by calling /api/v1/session
	u := strings.TrimRight(cfg.PhotoPrismBaseURL, "/") + "/api/v1/session"
	req, _ := http.NewRequest("GET", u, nil)
	if cfg.PhotoPrismToken != "" {
		req.Header.Set("Authorization", "Bearer "+cfg.PhotoPrismToken)
		req.Header.Set("X-Auth-Token", cfg.PhotoPrismToken)
	}
	if cfg.PhotoPrismAPIKey != "" {
		req.Header.Set("X-API-Key", cfg.PhotoPrismAPIKey)
	}
	client := &http.Client{Timeout: 10 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return ""
	}
	defer resp.Body.Close()
	// Photoprism may return X-Preview-Token header
	if t := resp.Header.Get("X-Preview-Token"); t != "" {
		return t
	}
	// otherwise try to decode body and look for token
	b, _ := io.ReadAll(resp.Body)
	var obj map[string]any
	_ = json.Unmarshal(b, &obj)
	if token, ok := obj["PreviewToken"].(string); ok {
		return token
	}
	return ""
}

func deriveAlbumsFromPhotos(category string) ([]map[string]any, error) {
	photos, err := listPhotos(1000, "", category, "newest")
	if err != nil {
		return nil, err
	}
	seen := map[string]map[string]any{}
	for _, p := range photos {
		// prefer embedded
		for _, key := range []string{"Albums", "albums"} {
			if arr, ok := p[key].([]any); ok {
				for _, it := range arr {
					switch at := it.(type) {
					case string:
						seen[at] = map[string]any{"UID": "", "Title": at}
					case map[string]any:
						title := toString(at["Title"])
						if title == "" {
							title = toString(at["title"])
						}
						uid := toString(at["UID"])
						if title != "" {
							seen[title] = map[string]any{"UID": uid, "Title": title}
						}
					}
				}
			}
		}
		// fallback derive from Path
		if path := toString(p["Path"]); path != "" && strings.Contains(strings.ToLower(path), strings.ToLower(category)) {
			parts := strings.Split(path, "/")
			// take last segment after category
			for i, seg := range parts {
				if strings.EqualFold(seg, category) && i+1 < len(parts) {
					candidate := parts[len(parts)-1]
					if candidate != "" {
						seen[candidate] = map[string]any{"UID": "", "Title": candidate}
					}
				}
			}
		}
	}
	var out []map[string]any
	for _, v := range seen {
		out = append(out, v)
	}
	return out, nil
}
