## Makefile - helper targets for local development

PKG := ./cmd/api

ifeq ($(OS),Windows_NT)
BINARY := image-mosaic.exe
else
BINARY := image-mosaic
endif

.PHONY: all build run embed clean fmt deps

all: build

deps:
	go mod download

fmt:
	gofmt -w $(PKG)

ifeq ($(OS),Windows_NT)
    EMBED_CMD = powershell -NoProfile -ExecutionPolicy Bypass -File .\\scripts\\prepare_embed.ps1
else
    EMBED_CMD = sh -c 'if [ -d dist ]; then if [ -d public ]; then cp -a public/. dist/ || true; fi; mkdir -p $(PKG)/public; cp -a dist/. $(PKG)/public; echo "Copied dist -> $(PKG)/public"; elif [ -d public ]; then mkdir -p $(PKG)/public; cp -a public/. $(PKG)/public; echo "Copied public -> $(PKG)/public"; else echo "No dist or public directory found; run npm run build first"; fi'
endif

ifeq ($(OS),Windows_NT)
    NPM_BUILD_CMD = powershell -NoProfile -ExecutionPolicy Bypass -Command "if (Test-Path 'package-lock.json') { npm ci } else { npm install }; npm run build"
else
    NPM_BUILD_CMD = sh -c 'if [ -f package-lock.json ]; then npm ci; else npm install; fi; npm run build'
endif

embed:
	@echo "Preparing embedded assets..."
	@$(EMBED_CMD)

build: deps npm-build embed
	go build -o $(BINARY) $(PKG)

npm-build:
	@echo "Running frontend build (npm run build)..."
	@$(NPM_BUILD_CMD)

run: build
ifeq ($(OS),Windows_NT)
	powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\run_image_mosaic.ps1
else
	./$(BINARY)
endif

clean:
	-rm -f $(BINARY)
	-rm -rf $(PKG)/public
