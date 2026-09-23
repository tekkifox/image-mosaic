package main

import (
	"bytes"
	"crypto/sha1"
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
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
	"unicode"

	"golang.org/x/text/transform"
	"golang.org/x/text/unicode/norm"
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
		return countryCodeToName(country)
	}

	// translation is performed using translateText (with global cache)

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
			// translate place name (or transliterate) to English-friendly form
			display = translateText(display)
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

// countryCodeToName converts 2-letter country codes to English full names when possible.
func countryCodeToName(code string) string {
	if code == "" {
		return ""
	}
	c := strings.TrimSpace(code)
	if c == "" {
		return ""
	}
	up := strings.ToUpper(c)
	if name, ok := countryNames[up]; ok {
		return name
	}
	// if input already looks like a full name, normalize spacing and title-case
	return strings.Title(strings.ToLower(c))
}

var countryNames = map[string]string{
	"AF": "Afghanistan",
	"AL": "Albania",
	"DZ": "Algeria",
	"AS": "American Samoa",
	"AD": "Andorra",
	"AO": "Angola",
	"AI": "Anguilla",
	"AQ": "Antarctica",
	"AG": "Antigua and Barbuda",
	"AR": "Argentina",
	"AM": "Armenia",
	"AW": "Aruba",
	"AU": "Australia",
	"AT": "Austria",
	"AZ": "Azerbaijan",
	"BS": "Bahamas",
	"BH": "Bahrain",
	"BD": "Bangladesh",
	"BB": "Barbados",
	"BY": "Belarus",
	"BE": "Belgium",
	"BZ": "Belize",
	"BJ": "Benin",
	"BM": "Bermuda",
	"BT": "Bhutan",
	"BO": "Bolivia",
	"BA": "Bosnia and Herzegovina",
	"BW": "Botswana",
	"BR": "Brazil",
	"BN": "Brunei",
	"BG": "Bulgaria",
	"BF": "Burkina Faso",
	"BI": "Burundi",
	"KH": "Cambodia",
	"CM": "Cameroon",
	"CA": "Canada",
	"CV": "Cabo Verde",
	"KY": "Cayman Islands",
	"CF": "Central African Republic",
	"TD": "Chad",
	"CL": "Chile",
	"CN": "China",
	"CX": "Christmas Island",
	"CO": "Colombia",
	"KM": "Comoros",
	"CD": "Congo (Democratic Republic)",
	"CG": "Congo (Republic)",
	"CR": "Costa Rica",
	"CI": "Côte d'Ivoire",
	"HR": "Croatia",
	"CU": "Cuba",
	"CY": "Cyprus",
	"CZ": "Czechia",
	"DK": "Denmark",
	"DJ": "Djibouti",
	"DM": "Dominica",
	"DO": "Dominican Republic",
	"EC": "Ecuador",
	"EG": "Egypt",
	"SV": "El Salvador",
	"GQ": "Equatorial Guinea",
	"ER": "Eritrea",
	"EE": "Estonia",
	"SZ": "Eswatini",
	"ET": "Ethiopia",
	"FK": "Falkland Islands",
	"FO": "Faroe Islands",
	"FJ": "Fiji",
	"FI": "Finland",
	"FR": "France",
	"GF": "French Guiana",
	"PF": "French Polynesia",
	"GA": "Gabon",
	"GM": "Gambia",
	"GE": "Georgia",
	"DE": "Germany",
	"GH": "Ghana",
	"GI": "Gibraltar",
	"GR": "Greece",
	"GL": "Greenland",
	"GD": "Grenada",
	"GP": "Guadeloupe",
	"GU": "Guam",
	"GT": "Guatemala",
	"GN": "Guinea",
	"GW": "Guinea-Bissau",
	"GY": "Guyana",
	"HT": "Haiti",
	"HN": "Honduras",
	"HK": "Hong Kong",
	"HU": "Hungary",
	"IS": "Iceland",
	"IN": "India",
	"ID": "Indonesia",
	"IR": "Iran",
	"IQ": "Iraq",
	"IE": "Ireland",
	"IM": "Isle of Man",
	"IL": "Israel",
	"IT": "Italy",
	"JM": "Jamaica",
	"JP": "Japan",
	"JO": "Jordan",
	"KZ": "Kazakhstan",
	"KE": "Kenya",
	"KI": "Kiribati",
	"KP": "North Korea",
	"KR": "South Korea",
	"KW": "Kuwait",
	"KG": "Kyrgyzstan",
	"LA": "Laos",
	"LV": "Latvia",
	"LB": "Lebanon",
	"LS": "Lesotho",
	"LR": "Liberia",
	"LY": "Libya",
	"LI": "Liechtenstein",
	"LT": "Lithuania",
	"LU": "Luxembourg",
	"MO": "Macao",
	"MG": "Madagascar",
	"MW": "Malawi",
	"MY": "Malaysia",
	"MV": "Maldives",
	"ML": "Mali",
	"MT": "Malta",
	"MH": "Marshall Islands",
	"MQ": "Martinique",
	"MR": "Mauritania",
	"MU": "Mauritius",
	"YT": "Mayotte",
	"MX": "Mexico",
	"FM": "Micronesia",
	"MD": "Moldova",
	"MC": "Monaco",
	"MN": "Mongolia",
	"ME": "Montenegro",
	"MS": "Montserrat",
	"MA": "Morocco",
	"MZ": "Mozambique",
	"MM": "Myanmar",
	"NA": "Namibia",
	"NR": "Nauru",
	"NP": "Nepal",
	"NL": "Netherlands",
	"NC": "New Caledonia",
	"NZ": "New Zealand",
	"NI": "Nicaragua",
	"NE": "Niger",
	"NG": "Nigeria",
	"NU": "Niue",
	"MK": "North Macedonia",
	"MP": "Northern Mariana Islands",
	"NO": "Norway",
	"OM": "Oman",
	"PK": "Pakistan",
	"PW": "Palau",
	"PS": "Palestine",
	"PA": "Panama",
	"PG": "Papua New Guinea",
	"PY": "Paraguay",
	"PE": "Peru",
	"PH": "Philippines",
	"PL": "Poland",
	"PT": "Portugal",
	"PR": "Puerto Rico",
	"QA": "Qatar",
	"RE": "Réunion",
	"RO": "Romania",
	"RU": "Russia",
	"RW": "Rwanda",
	"BL": "Saint Barthélemy",
	"SH": "Saint Helena",
	"KN": "Saint Kitts and Nevis",
	"LC": "Saint Lucia",
	"MF": "Saint Martin",
	"PM": "Saint Pierre and Miquelon",
	"VC": "Saint Vincent and the Grenadines",
	"WS": "Samoa",
	"SM": "San Marino",
	"ST": "Sao Tome and Principe",
	"SA": "Saudi Arabia",
	"SN": "Senegal",
	"RS": "Serbia",
	"SC": "Seychelles",
	"SL": "Sierra Leone",
	"SG": "Singapore",
	"SX": "Sint Maarten",
	"SK": "Slovakia",
	"SI": "Slovenia",
	"SB": "Solomon Islands",
	"SO": "Somalia",
	"ZA": "South Africa",
	"SS": "South Sudan",
	"ES": "Spain",
	"LK": "Sri Lanka",
	"SD": "Sudan",
	"SR": "Suriname",
	"SE": "Sweden",
	"CH": "Switzerland",
	"SY": "Syria",
	"TW": "Taiwan",
	"TJ": "Tajikistan",
	"TZ": "Tanzania",
	"TH": "Thailand",
	"TL": "Timor-Leste",
	"TG": "Togo",
	"TK": "Tokelau",
	"TO": "Tonga",
	"TT": "Trinidad and Tobago",
	"TN": "Tunisia",
	"TR": "Turkey",
	"TM": "Turkmenistan",
	"TC": "Turks and Caicos Islands",
	"TV": "Tuvalu",
	"UG": "Uganda",
	"UA": "Ukraine",
	"AE": "United Arab Emirates",
	"GB": "United Kingdom",
	"UK": "United Kingdom",
	"US": "United States",
	"UY": "Uruguay",
	"UZ": "Uzbekistan",
	"VU": "Vanuatu",
	"VE": "Venezuela",
	"VN": "Vietnam",
	"VG": "British Virgin Islands",
	"VI": "U.S. Virgin Islands",
	"WF": "Wallis and Futuna",
	"EH": "Western Sahara",
	"YE": "Yemen",
	"ZM": "Zambia",
	"ZW": "Zimbabwe",
	"ZZ": "Unknown",
}

var translateCache = map[string]string{}
var translateCacheMu sync.RWMutex

// translateText tries to translate text to English using a configured translation API (libre-style).
// Falls back to simple transliteration if no API is configured.
func translateText(text string) string {
	if text == "" {
		return text
	}
	translateCacheMu.RLock()
	if v, ok := translateCache[text]; ok {
		translateCacheMu.RUnlock()
		return v
	}
	translateCacheMu.RUnlock()
	// Prefer Google Translate when an API key is configured
	if cfg.TranslateAPIKey != "" {
		gURL := "https://translation.googleapis.com/language/translate/v2?key=" + url.QueryEscape(cfg.TranslateAPIKey)
		// Google accepts JSON body with q, target, format
		reqBody := map[string]any{"q": text, "target": "en", "format": "text"}
		b, _ := json.Marshal(reqBody)
		req, _ := http.NewRequest("POST", gURL, bytes.NewReader(b))
		req.Header.Set("Content-Type", "application/json")
		client := &http.Client{Timeout: 10 * time.Second}
		resp, err := client.Do(req)
		if err == nil {
			defer resp.Body.Close()
			body, _ := io.ReadAll(resp.Body)
			var obj map[string]any
			if json.Unmarshal(body, &obj) == nil {
				if data, ok := obj["data"].(map[string]any); ok {
					if arr, ok := data["translations"].([]any); ok && len(arr) > 0 {
						if first, ok := arr[0].(map[string]any); ok {
							if t, ok := first["translatedText"].(string); ok && t != "" {
								translateCacheMu.Lock()
								translateCache[text] = t
								translateCacheMu.Unlock()
								return t
							}
						}
					}
				}
			}
		}
	}

	// Try a configured Translate API URL, otherwise try a public LibreTranslate endpoint without an API key
	candidates := []string{}
	if cfg.TranslateAPIURL != "" {
		candidates = append(candidates, cfg.TranslateAPIURL)
	} else {
		// default public LibreTranslate endpoints (no API key required for light usage)
		candidates = append(candidates, "https://libretranslate.com/translate")
		candidates = append(candidates, "https://translate.argosopentech.com/translate")
	}

	for _, apiURL := range candidates {
		reqBody := map[string]string{"q": text, "source": "auto", "target": "en", "format": "text"}
		b, _ := json.Marshal(reqBody)
		req, _ := http.NewRequest("POST", apiURL, bytes.NewReader(b))
		req.Header.Set("Content-Type", "application/json")
		if cfg.TranslateAPIKey != "" {
			req.Header.Set("Authorization", "Bearer "+cfg.TranslateAPIKey)
			req.Header.Set("X-API-Key", cfg.TranslateAPIKey)
		}
		client := &http.Client{Timeout: 10 * time.Second}
		resp, err := client.Do(req)
		if err != nil {
			continue
		}
		defer resp.Body.Close()
		body, _ := io.ReadAll(resp.Body)
		var obj map[string]any
		if json.Unmarshal(body, &obj) == nil {
			if v, ok := obj["translatedText"].(string); ok && v != "" {
				translateCacheMu.Lock()
				translateCache[text] = v
				translateCacheMu.Unlock()
				return v
			}
			if data, ok := obj["data"].(map[string]any); ok {
				if arr, ok := data["translations"].([]any); ok && len(arr) > 0 {
					if first, ok := arr[0].(map[string]any); ok {
						if t, ok := first["translatedText"].(string); ok && t != "" {
							translateCacheMu.Lock()
							translateCache[text] = t
							translateCacheMu.Unlock()
							return t
						}
					}
				}
			}
			// some APIs (Libre) return {"translatedText":"..."} directly, but try to parse plain string too
			var plain string
			if json.Unmarshal(body, &plain) == nil && plain != "" {
				translateCacheMu.Lock()
				translateCache[text] = plain
				translateCacheMu.Unlock()
				return plain
			}
		}
	}

	// fallback transliteration
	out := transliterate(text)
	translateCacheMu.Lock()
	translateCache[text] = out
	translateCacheMu.Unlock()
	return out
}

// transliterate removes diacritics and attempts to normalize text into ASCII-friendly form.
func transliterate(s string) string {
	// NFD normalization then drop non-spacing marks (diacritics)
	t := norm.NFD
	res, _, err := transform.String(t, s)
	if err != nil {
		return s
	}
	// remove diacritics
	runes := make([]rune, 0, len(res))
	for _, r := range res {
		if unicode.Is(unicode.Mn, r) {
			continue
		}
		runes = append(runes, r)
	}
	out := string(runes)
	// collapse whitespace
	out = strings.Join(strings.Fields(out), " ")
	return out
}

type Config struct {
	PhotoPrismBaseURL       string
	PhotoPrismToken         string
	PhotoPrismAPIKey        string
	PhotoPrismUsername      string
	PhotoPrismPassword      string
	PhotoPrismPreviewToken  string
	PhotoPrismDownloadToken string
	TranslateAPIURL         string
	TranslateAPIKey         string
}

var cfg Config
var lastRequest any

func main() {
	cfg = Config{
		PhotoPrismBaseURL:       getenv("PHOTO_PRISM_BASE_URL", "https://photoprism.example.com"),
		PhotoPrismToken:         getenv("PHOTO_PRISM_ACCESS_TOKEN", ""),
		PhotoPrismAPIKey:        getenv("PHOTO_PRISM_API_KEY", ""),
		PhotoPrismUsername:      getenv("PHOTO_PRISM_USERNAME", ""),
		PhotoPrismPassword:      getenv("PHOTO_PRISM_PASSWORD", ""),
		PhotoPrismPreviewToken:  getenv("PHOTO_PRISM_PREVIEW_TOKEN", ""),
		PhotoPrismDownloadToken: getenv("PHOTO_PRISM_DOWNLOAD_TOKEN", ""),
		TranslateAPIURL:         getenv("TRANSLATE_API_URL", ""),
		TranslateAPIKey:         getenv("TRANSLATE_API_KEY", ""),
	}
	// Normalize PhotoPrism base URL: strip trailing slashes and any trailing /api or /api/vN
	cfg.PhotoPrismBaseURL = normalizePhotoPrismBaseURL(cfg.PhotoPrismBaseURL)

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

	// Note: image proxy endpoint removed — tiles now use direct PhotoPrism URLs when possible

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

// writeJSONWithCache writes JSON response and sets Cache-Control and ETag headers.
func writeJSONWithCache(w http.ResponseWriter, status int, v any, maxAgeSeconds int) {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(v)
	body := buf.Bytes()
	// set headers
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if maxAgeSeconds > 0 {
		w.Header().Set("Cache-Control", fmt.Sprintf("public, max-age=%d", maxAgeSeconds))
	}
	// set ETag based on body
	et := fmt.Sprintf("\"%x\"", sha1.Sum(body))
	w.Header().Set("ETag", et)
	w.WriteHeader(status)
	_, _ = w.Write(body)
}

// normalizePhotoPrismBaseURL removes trailing slashes and any trailing /api or /api/vN
func normalizePhotoPrismBaseURL(u string) string {
	s := strings.TrimSpace(u)
	if s == "" {
		return s
	}
	s = strings.TrimRight(s, "/")
	re := regexp.MustCompile(`(?i)(?:/api(?:/v[0-9]+)?)$`)
	s = re.ReplaceAllString(s, "")
	return s
}

// debugHandler returns connection status and last request.
func debugHandler(w http.ResponseWriter, r *http.Request) {
	resp := map[string]any{
		"connection": map[string]any{
			"baseUrl":         cfg.PhotoPrismBaseURL,
			"hasAccessToken":  cfg.PhotoPrismToken != "",
			"hasApiKey":       cfg.PhotoPrismAPIKey != "",
			"hasPreviewToken": cfg.PhotoPrismPreviewToken != "",
			"hasCredentials":  cfg.PhotoPrismUsername != "",
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
	// Cache tiles response briefly to reduce repeated downstream load
	writeJSONWithCache(w, http.StatusOK, map[string]any{
		"columns": 12,
		"rows":    12,
		"tiles":   tiles,
		"total":   total,
		"offset":  offset,
		"limit":   limit,
		"hasMore": (offset+len(tiles) < total),
	}, 30)
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
	if err != nil {
		lastRequest = map[string]any{"url": u.String(), "method": "GET", "error": err.Error()}
		return nil, err
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	// record last request including response status and a small body snippet for debugging
	snippet := string(body)
	if len(snippet) > 1024 {
		snippet = snippet[:1024]
	}
	lastRequest = map[string]any{"url": u.String(), "method": "GET", "status": resp.StatusCode, "body": snippet}
	if resp.StatusCode >= 400 {
		return nil, fmt.Errorf("PhotoPrism API request failed (%d): %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}
	var out []map[string]any
	if len(body) == 0 {
		return out, nil
	}

	// First try decode as a top-level array
	if err := json.Unmarshal(body, &out); err == nil {
		return out, nil
	}

	// Try decoding envelope objects that contain the array under common keys
	var anyObj any
	if err := json.Unmarshal(body, &anyObj); err == nil {
		switch v := anyObj.(type) {
		case []any:
			for _, it := range v {
				if mm, ok := it.(map[string]any); ok {
					out = append(out, mm)
				}
			}
			return out, nil
		case map[string]any:
			// common envelope keys used by various APIs
			keys := []string{"photos", "Photos", "items", "Items", "results", "Results"}
			for _, k := range keys {
				if arr, ok := v[k]; ok {
					if arrA, ok2 := arr.([]any); ok2 {
						for _, it := range arrA {
							if mm, ok := it.(map[string]any); ok {
								out = append(out, mm)
							}
						}
						return out, nil
					}
				}
			}
		}
	}
	// fallback: return empty slice
	return out, nil
}

func listPhotos(limit int, album, category, order string) ([]map[string]any, error) {
	// Try multiple query strategies in order:
	// 1) /api/v1/photos/view?q=category:"..."
	// 2) /api/v1/photos/view?q=path:"..."
	// 3) /api/v1/photos?q=category:"..."
	// 4) /api/v1/photos?q=path:"..."
	// 5) fallback: fetch a large sample and filter by Path substring

	if category != "" {
		// viewer-formatted by category
		p := map[string]string{"count": strconv.Itoa(limit), "order": order, "q": fmt.Sprintf("category:\"%s\"", category)}
		photos, err := photosRequest("/api/v1/photos/view", p)
		if err == nil && len(photos) > 0 {
			return photos, nil
		}

		// viewer-formatted by path
		p["q"] = fmt.Sprintf("path:\"%s\"", category)
		photos, err = photosRequest("/api/v1/photos/view", p)
		if err == nil && len(photos) > 0 {
			return photos, nil
		}

		// plain photos by category
		p2 := map[string]string{"count": strconv.Itoa(limit), "order": order, "q": fmt.Sprintf("category:\"%s\"", category)}
		photos, err = photosRequest("/api/v1/photos", p2)
		if err == nil && len(photos) > 0 {
			return photos, nil
		}

		// plain photos by path
		p2["q"] = fmt.Sprintf("path:\"%s\"", category)
		photos, err = photosRequest("/api/v1/photos", p2)
		if err == nil && len(photos) > 0 {
			return photos, nil
		}

		// last-resort: fetch a large sample and filter by Path substring
		allParams := map[string]string{"count": "10000", "order": order}
		allPhotos, err2 := photosRequest("/api/v1/photos", allParams)
		if err2 == nil && len(allPhotos) > 0 {
			catLower := strings.ToLower(category)
			var out []map[string]any
			for _, p := range allPhotos {
				if path := toString(p["Path"]); path != "" {
					if strings.Contains(strings.ToLower(path), catLower) {
						out = append(out, p)
						if len(out) >= limit {
							break
						}
					}
				}
			}
			return out, nil
		}
		return []map[string]any{}, nil
	}

	// No category: request viewer-formatted entries directly to include thumbnails and viewer fields
	params := map[string]string{"count": strconv.Itoa(limit), "order": order}
	if album != "" {
		params["q"] = fmt.Sprintf("albums:\"%s\"", album)
	}
	photos, err := photosRequest("/api/v1/photos/view", params)
	if err == nil && len(photos) > 0 {
		return photos, nil
	}
	// fallback to plain photos if viewer endpoint didn't return results
	photos, err = photosRequest("/api/v1/photos", params)
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

	// photos passed into buildTiles are expected to be viewer-formatted entries (from /api/v1/photos/view)
	// Build viewMap from the provided photos so we do not need an extra batch /api/v1/photos call.
	viewMap := map[string]map[string]any{}
	detailMap := map[string]map[string]any{}
	for _, v := range photos {
		id := toString(v["UID"])
		if id == "" {
			id = toString(v["uid"])
		}
		if id != "" {
			viewMap[id] = v
		}
		if h := toString(v["Hash"]); h != "" {
			viewMap["hash:"+h] = v
		}
	}

	// Collect UIDs that still lack album information and fetch details for them concurrently.
	var missingUIDs []string
	seenUID := map[string]bool{}
	for _, p := range photos {
		uid := toString(p["UID"])
		if uid == "" {
			uid = toString(p["PhotoUID"])
		}
		if uid == "" {
			continue
		}

		// Skip if photo already includes album info
		hasAlbums := false
		if arr, ok := p["Albums"].([]any); ok && len(arr) > 0 {
			hasAlbums = true
		}
		if !hasAlbums {
			if at := toString(p["Album"]); at != "" {
				hasAlbums = true
			}
			if at := toString(p["AlbumTitle"]); at != "" {
				hasAlbums = true
			}
		}

		// Skip if batch detail already includes albums
		if !hasAlbums {
			if d, ok := detailMap[uid]; ok && d != nil {
				if arr, ok := d["Albums"].([]any); ok && len(arr) > 0 {
					hasAlbums = true
				}
			}
		}

		if !hasAlbums {
			if !seenUID[uid] {
				missingUIDs = append(missingUIDs, uid)
				seenUID[uid] = true
			}
		}
	}

	if len(missingUIDs) > 0 {
		var wg sync.WaitGroup
		sem := make(chan struct{}, 8) // concurrency limit
		var mu sync.Mutex
		for _, uid := range missingUIDs {
			wg.Add(1)
			go func(uid string) {
				defer wg.Done()
				sem <- struct{}{}
				d, err := getPhotoDetail(uid)
				<-sem
				if err == nil && d != nil {
					mu.Lock()
					detailMap[uid] = d
					if h := toString(d["Hash"]); h != "" {
						detailMap["hash:"+h] = d
					}
					mu.Unlock()
				}
			}(uid)
		}
		wg.Wait()
	}

	for _, p := range photos {
		title := toString(p["Title"])
		if title == "" {
			title = toString(p["title"])
		}
		hash := toString(p["Hash"])
		if hash == "" {
			hash = toString(p["hash"])
		}
		uid := toString(p["UID"])
		if uid == "" {
			uid = toString(p["PhotoUID"])
		}

		// Build thumb/medium/full URLs using viewer batch results, with consistent sizes:
		//  - thumb: tile_224
		//  - medium: fit_720
		//  - full: fit_1280
		var thumb, medium, full string
		base := strings.TrimRight(cfg.PhotoPrismBaseURL, "/")

		// helper to pick exact size key from Thumbs map
		pickExact := func(thumbs map[string]any, key string) string {
			if it, ok := thumbs[key]; ok {
				if m, ok := it.(map[string]any); ok {
					if src := toString(m["src"]); src != "" {
						if strings.HasPrefix(src, "/") {
							return base + src
						}
						return src
					}
				}
			}
			return ""
		}

		// prefer viewer-provided Thumbs when available
		if uid != "" {
			if v, ok := viewMap[uid]; ok && v != nil {
				if thumbs, ok := v["Thumbs"].(map[string]any); ok {
					thumb = pickExact(thumbs, "tile_224")
					medium = pickExact(thumbs, "fit_720")
					full = pickExact(thumbs, "fit_1280")
				}
			}
		}
		if thumb == "" && hash != "" {
			if v, ok := viewMap["hash:"+hash]; ok && v != nil {
				if thumbs, ok := v["Thumbs"].(map[string]any); ok {
					if thumb == "" {
						thumb = pickExact(thumbs, "tile_224")
					}
					if medium == "" {
						medium = pickExact(thumbs, "fit_720")
					}
					if full == "" {
						full = pickExact(thumbs, "fit_1280")
					}
				}
			}
		}

		// If not present, construct tokenized URLs (preferred) or direct downloads as fallback
		if thumb == "" {
			if hash != "" && cfg.PhotoPrismPreviewToken != "" {
				thumb = fmt.Sprintf("%s/api/v1/t/%s/%s/%s", base, url.PathEscape(hash), url.PathEscape(cfg.PhotoPrismPreviewToken), "tile_224")
			} else if uid != "" {
				thumb = fmt.Sprintf("%s/api/v1/photos/%s/dl", base, url.PathEscape(uid))
			}
		}
		if medium == "" {
			if hash != "" && cfg.PhotoPrismPreviewToken != "" {
				medium = fmt.Sprintf("%s/api/v1/t/%s/%s/%s", base, url.PathEscape(hash), url.PathEscape(cfg.PhotoPrismPreviewToken), "fit_720")
			} else if uid != "" {
				medium = fmt.Sprintf("%s/api/v1/photos/%s/dl", base, url.PathEscape(uid))
			} else {
				medium = thumb
			}
		}
		if full == "" {
			if hash != "" && cfg.PhotoPrismPreviewToken != "" {
				full = fmt.Sprintf("%s/api/v1/t/%s/%s/%s", base, url.PathEscape(hash), url.PathEscape(cfg.PhotoPrismPreviewToken), "fit_1280")
			} else if uid != "" {
				full = fmt.Sprintf("%s/api/v1/photos/%s/dl", base, url.PathEscape(uid))
			} else {
				full = medium
			}
		}

		// Final fallback: construct direct PhotoPrism URLs (prefer tokenized /api/v1/t when preview token available)
		if thumb == "" {
			if hash != "" && cfg.PhotoPrismPreviewToken != "" {
				thumb = fmt.Sprintf("%s/api/v1/t/%s/%s/%s", base, url.PathEscape(hash), url.PathEscape(cfg.PhotoPrismPreviewToken), "tile_224")
			} else if uid != "" {
				thumb = fmt.Sprintf("%s/api/v1/photos/%s/dl", base, url.PathEscape(uid))
			} else if hash != "" {
				// fallback: direct photos query (not an image URL, but better than local proxy)
				thumb = fmt.Sprintf("%s/api/v1/photos?count=1&q=hash:%s", base, url.QueryEscape(hash))
			}
		}
		if medium == "" {
			if hash != "" && cfg.PhotoPrismPreviewToken != "" {
				medium = fmt.Sprintf("%s/api/v1/t/%s/%s/%s", base, url.PathEscape(hash), url.PathEscape(cfg.PhotoPrismPreviewToken), "fit_720")
			} else if uid != "" {
				medium = fmt.Sprintf("%s/api/v1/photos/%s/dl", base, url.PathEscape(uid))
			} else if hash != "" {
				medium = fmt.Sprintf("%s/api/v1/photos?count=1&q=hash:%s", base, url.QueryEscape(hash))
			}
		}
		if full == "" {
			if hash != "" && cfg.PhotoPrismPreviewToken != "" {
				full = fmt.Sprintf("%s/api/v1/t/%s/%s/%s", base, url.PathEscape(hash), url.PathEscape(cfg.PhotoPrismPreviewToken), "fit_1920")
			} else if uid != "" {
				full = fmt.Sprintf("%s/api/v1/photos/%s/dl", base, url.PathEscape(uid))
			} else if hash != "" {
				full = fmt.Sprintf("%s/api/v1/photos?count=1&q=hash:%s", base, url.QueryEscape(hash))
			}
		}

		// collect album titles
		albumTitles := []string{}
		for _, key := range []string{"Albums", "albums"} {
			if arr, ok := p[key].([]any); ok {
				for _, it := range arr {
					switch at := it.(type) {
					case string:
						if at != "" {
							albumTitles = append(albumTitles, at)
						}
					case map[string]any:
						t := toString(at["Title"])
						if t == "" {
							t = toString(at["title"])
						}
						if t != "" {
							albumTitles = append(albumTitles, t)
						}
					}
				}
			}
		}
		// fallback: Album/AlbumTitle keys
		if len(albumTitles) == 0 {
			if at := toString(p["Album"]); at != "" {
				albumTitles = append(albumTitles, at)
			}
			if at := toString(p["AlbumTitle"]); at != "" {
				albumTitles = append(albumTitles, at)
			}
		}
		// try batch-fetched detailMap
		if len(albumTitles) == 0 {
			if uid != "" {
				if d, ok := detailMap[uid]; ok && d != nil {
					if arr, ok := d["Albums"].([]any); ok {
						for _, it := range arr {
							if m, ok := it.(map[string]any); ok {
								t := toString(m["Title"])
								if t == "" {
									t = toString(m["title"])
								}
								if t != "" {
									albumTitles = append(albumTitles, t)
								}
							} else if s, ok := it.(string); ok && s != "" {
								albumTitles = append(albumTitles, s)
							}
						}
					}
				}
			}
			if len(albumTitles) == 0 && hash != "" {
				if d, ok := detailMap["hash:"+hash]; ok && d != nil {
					if arr, ok := d["Albums"].([]any); ok {
						for _, it := range arr {
							if m, ok := it.(map[string]any); ok {
								t := toString(m["Title"])
								if t == "" {
									t = toString(m["title"])
								}
								if t != "" {
									albumTitles = append(albumTitles, t)
								}
							} else if s, ok := it.(string); ok && s != "" {
								albumTitles = append(albumTitles, s)
							}
						}
					}
				}
			}
		}

		tiles = append(tiles, map[string]any{
			"title":      title,
			"albums":     albumTitles,
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
	// If a preview token was provided via env, use it
	if cfg.PhotoPrismPreviewToken != "" {
		return cfg.PhotoPrismPreviewToken
	}
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
		// don't return yet; try credentials if available
		if cfg.PhotoPrismUsername == "" || cfg.PhotoPrismPassword == "" {
			return ""
		}
	}
	if resp != nil {
		defer resp.Body.Close()
		// Photoprism may return X-Preview-Token header
		if t := resp.Header.Get("X-Preview-Token"); t != "" {
			cfg.PhotoPrismPreviewToken = t
			return t
		}
		// otherwise try to decode body and look for token
		b, _ := io.ReadAll(resp.Body)
		var obj map[string]any
		_ = json.Unmarshal(b, &obj)
		if token, ok := obj["PreviewToken"].(string); ok && token != "" {
			cfg.PhotoPrismPreviewToken = token
			return token
		}
	}

	// If GET didn't yield a preview token and credentials are available, POST to create a session
	if cfg.PhotoPrismUsername != "" && cfg.PhotoPrismPassword != "" {
		postURL := strings.TrimRight(cfg.PhotoPrismBaseURL, "/") + "/api/v1/session"
		payload := map[string]string{"username": cfg.PhotoPrismUsername, "password": cfg.PhotoPrismPassword}
		bodyBytes, _ := json.Marshal(payload)
		req2, _ := http.NewRequest("POST", postURL, bytes.NewReader(bodyBytes))
		req2.Header.Set("Content-Type", "application/json")
		client2 := &http.Client{Timeout: 10 * time.Second}
		resp2, err2 := client2.Do(req2)
		if err2 != nil {
			// record lastRequest minimally
			lastRequest = map[string]any{"url": postURL, "method": "POST", "error": err2.Error()}
			return ""
		}
		defer resp2.Body.Close()
		// try header first
		if t := resp2.Header.Get("X-Preview-Token"); t != "" {
			lastRequest = map[string]any{"url": postURL, "method": "POST", "status": resp2.StatusCode}
			// If the response included an access token and we don't already have one, adopt it for subsequent requests
			b2, _ := io.ReadAll(resp2.Body)
			var obj2 map[string]any
			_ = json.Unmarshal(b2, &obj2)
			if at, ok := obj2["access_token"].(string); ok && at != "" && cfg.PhotoPrismToken == "" {
				cfg.PhotoPrismToken = at
			}
			cfg.PhotoPrismPreviewToken = t
			return t
		}
		b2, _ := io.ReadAll(resp2.Body)
		var obj2 map[string]any
		_ = json.Unmarshal(b2, &obj2)
		snippet := string(b2)
		if len(snippet) > 1024 {
			snippet = snippet[:1024]
		}
		lastRequest = map[string]any{"url": postURL, "method": "POST", "status": resp2.StatusCode, "body": snippet}
		if token, ok := obj2["PreviewToken"].(string); ok && token != "" {
			if at, ok := obj2["access_token"].(string); ok && at != "" && cfg.PhotoPrismToken == "" {
				cfg.PhotoPrismToken = at
			}
			cfg.PhotoPrismPreviewToken = token
			return token
		}
		// use returned access_token as a fallback for subsequent requests
		if at, ok := obj2["access_token"].(string); ok && at != "" {
			if cfg.PhotoPrismToken == "" {
				cfg.PhotoPrismToken = at
			}
		}
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

// getPhotoView fetches the viewer-formatted photo object which includes tokenized thumb URLs.
func getPhotoView(uid string) (map[string]any, error) {
	u := strings.TrimRight(cfg.PhotoPrismBaseURL, "/") + "/api/v1/photos/view?count=1&q=" + url.QueryEscape(fmt.Sprintf("uid:\"%s\"", uid))
	req, _ := http.NewRequest("GET", u, nil)
	if cfg.PhotoPrismToken != "" {
		req.Header.Set("Authorization", "Bearer "+cfg.PhotoPrismToken)
		req.Header.Set("X-Auth-Token", cfg.PhotoPrismToken)
	}
	if cfg.PhotoPrismAPIKey != "" {
		req.Header.Set("X-API-Key", cfg.PhotoPrismAPIKey)
	}
	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Do(req)
	// record last request for debugging
	if err != nil {
		lastRequest = map[string]any{"url": u, "method": "GET", "error": err.Error()}
		return nil, err
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	snippet := string(body)
	if len(snippet) > 1024 {
		snippet = snippet[:1024]
	}
	lastRequest = map[string]any{"url": u, "method": "GET", "status": resp.StatusCode, "body": snippet}

	if resp.StatusCode >= 400 {
		return nil, fmt.Errorf("photo view failed: %d", resp.StatusCode)
	}
	var obj map[string]any
	if err := json.Unmarshal(body, &obj); err != nil {
		return nil, err
	}
	return obj, nil
}

// getPhotoDetail fetches the full photo object (includes Albums and Files)
func getPhotoDetail(uid string) (map[string]any, error) {
	u := strings.TrimRight(cfg.PhotoPrismBaseURL, "/") + "/api/v1/photos/" + url.PathEscape(uid)
	req, _ := http.NewRequest("GET", u, nil)
	if cfg.PhotoPrismToken != "" {
		req.Header.Set("Authorization", "Bearer "+cfg.PhotoPrismToken)
		req.Header.Set("X-Auth-Token", cfg.PhotoPrismToken)
	}
	if cfg.PhotoPrismAPIKey != "" {
		req.Header.Set("X-API-Key", cfg.PhotoPrismAPIKey)
	}
	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 400 {
		return nil, fmt.Errorf("photo detail failed: %d", resp.StatusCode)
	}
	body, _ := io.ReadAll(resp.Body)
	var obj map[string]any
	if err := json.Unmarshal(body, &obj); err != nil {
		return nil, err
	}
	return obj, nil
}

// photoProxyHandler streams a photo file from PhotoPrism using the server-side access token.
// Query params: uid=<photo-uid> OR hash=<photo-hash>, optional size (ignored for download endpoint)
func photoProxyHandler(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	uid := q.Get("uid")
	hash := q.Get("hash")
	size := q.Get("size")
	if size == "" {
		size = "tile_224"
	}

	// If only hash provided, try to resolve UID via photosRequest
	if uid == "" && hash != "" {
		photos, err := photosRequest("/api/v1/photos", map[string]string{"count": "1", "q": fmt.Sprintf("hash:\"%s\"", hash)})
		if err != nil || len(photos) == 0 {
			http.Error(w, "photo not found", http.StatusNotFound)
			return
		}
		uid = toString(photos[0]["UID"])
	}

	// If we have a uid but no hash, try to resolve the hash from photo detail
	if hash == "" && uid != "" {
		if d, err := getPhotoDetail(uid); err == nil && d != nil {
			if h := toString(d["Hash"]); h != "" {
				hash = h
			} else if files, ok := d["Files"].([]any); ok && len(files) > 0 {
				if fm, ok := files[0].(map[string]any); ok {
					if fh := toString(fm["Hash"]); fh != "" {
						hash = fh
					}
				}
			}
		}
	}

	if uid == "" {
		http.Error(w, "uid or hash required", http.StatusBadRequest)
		return
	}

	// If a preview token and hash are available and a size was requested, use the tokenized thumbnail endpoint
	base := strings.TrimRight(cfg.PhotoPrismBaseURL, "/")
	var remote string
	if size != "" && cfg.PhotoPrismPreviewToken != "" && hash != "" {
		remote = fmt.Sprintf("%s/api/v1/t/%s/%s/%s", base, url.PathEscape(hash), url.PathEscape(cfg.PhotoPrismPreviewToken), size)
	} else {
		// Use the PhotoPrism download endpoint to stream the primary file
		remote = fmt.Sprintf("%s/api/v1/photos/%s/dl", base, url.PathEscape(uid))
	}
	req, _ := http.NewRequest("GET", remote, nil)
	if cfg.PhotoPrismToken != "" {
		req.Header.Set("Authorization", "Bearer "+cfg.PhotoPrismToken)
		req.Header.Set("X-Auth-Token", cfg.PhotoPrismToken)
	}
	if cfg.PhotoPrismAPIKey != "" {
		req.Header.Set("X-API-Key", cfg.PhotoPrismAPIKey)
	}

	client := &http.Client{Timeout: 60 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		http.Error(w, "failed to fetch photo", http.StatusBadGateway)
		return
	}
	defer resp.Body.Close()

	// Propagate status
	if resp.StatusCode >= 400 {
		body, _ := io.ReadAll(resp.Body)
		http.Error(w, fmt.Sprintf("upstream error: %d %s", resp.StatusCode, strings.TrimSpace(string(body))), http.StatusBadGateway)
		return
	}

	// Copy headers we care about
	if ct := resp.Header.Get("Content-Type"); ct != "" {
		w.Header().Set("Content-Type", ct)
	} else {
		w.Header().Set("Content-Type", "application/octet-stream")
	}
	// propagate upstream cache-control if present, otherwise set a default for images
	if cl := resp.Header.Get("Cache-Control"); cl != "" {
		w.Header().Set("Cache-Control", cl)
	} else {
		// tokenized thumbnails are safe to cache for a day; raw downloads also cacheable
		w.Header().Set("Cache-Control", "public, max-age=86400, immutable")
	}
	// propagate ETag from upstream when available
	if et := resp.Header.Get("ETag"); et != "" {
		w.Header().Set("ETag", et)
	}

	// If upstream provided a small content length, buffer to compute a local ETag when missing
	if resp.ContentLength >= 0 && resp.ContentLength <= 2*1024*1024 {
		body, _ := io.ReadAll(resp.Body)
		if resp.Header.Get("ETag") == "" {
			h := sha1.Sum(body)
			w.Header().Set("ETag", fmt.Sprintf("\"%x\"", h))
		}
		_, _ = w.Write(body)
		return
	}

	// Stream body (no ETag computed)
	_, _ = io.Copy(w, resp.Body)
}
