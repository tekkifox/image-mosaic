## Makefile - helper targets for local development

PKG := ./cmd/api

ifeq ($(OS),Windows_NT)
BINARY := image-mosaic.exe
else
BINARY := image-mosaic
endif

.PHONY: all build run embed clean fmt deps web-build

all: build

deps:
	go mod download

fmt:
	gofmt -w $(PKG)

ifeq ($(OS),Windows_NT)
    EMBED_CMD = powershell -NoProfile -ExecutionPolicy Bypass -File .\\scripts\\prepare_embed.ps1
else
    EMBED_CMD = sh -c 'if [ -d dist ]; then if [ -d public ]; then cp -a public/. dist/ || true; fi; mkdir -p $(PKG)/public; cp -a dist/. $(PKG)/public; echo "Copied dist -> $(PKG)/public"; elif [ -d public ]; then mkdir -p $(PKG)/public; cp -a public/. $(PKG)/public; echo "Copied public -> $(PKG)/public"; else echo "No dist or public directory found; run pnpm run build first"; fi'
endif

ifeq ($(OS),Windows_NT)
    WEB_BUILD_CMD = powershell -NoProfile -ExecutionPolicy Bypass -Command "pnpm install --frozen-lockfile; if ($$LASTEXITCODE -ne 0) { exit $$LASTEXITCODE }; pnpm run build"
else
    WEB_BUILD_CMD = sh -c 'pnpm install --frozen-lockfile && pnpm run build'
endif

embed:
	@echo "Preparing embedded assets..."
	@$(EMBED_CMD)

build: deps web-build embed
	go build -o $(BINARY) $(PKG)

web-build:
	@echo "Running frontend build (pnpm run build)..."
	@$(WEB_BUILD_CMD)

run: build
ifeq ($(OS),Windows_NT)
	powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\run_image_mosaic.ps1
else
	./$(BINARY)
endif

clean:
	-rm -f $(BINARY)
	-rm -rf $(PKG)/public
