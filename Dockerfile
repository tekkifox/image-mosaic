### Multi-stage build: build frontend assets (node) and Go binary, then produce minimal runtime image

### Frontend builder
FROM node:18-alpine AS node-builder
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --silent
COPY . .
RUN npm run build
    # After building, copy repository public/ (index and images) into dist/ so
    # the distribution directory contains index.html and images for embedding.
    RUN if [ -d public ]; then cp -a public/. dist/ || true; fi

### Go builder
FROM golang:1.26-alpine AS go-builder
WORKDIR /src
COPY cmd ./cmd
COPY go.mod go.sum ./
RUN apk add --no-cache git ca-certificates
RUN go env -w GOPROXY=https://proxy.golang.org
RUN go mod download
COPY . .
# Copy built frontend into cmd/api/public so embed will include static files
# Copy repository public/ (images, index.html) into cmd/api/public for embedding
# Copy built frontend assets from node-builder (assumes build output to dist/)
# Node builder merges public/ into dist/, so copy dist directly to include built assets for go:embed
# First copy repository public/ so its files are present
# then overlay with built assets from node-builder's dist so built files take precedence
COPY public ./cmd/api/public
COPY --from=node-builder /src/dist ./cmd/api/public
# Also ensure generated docs are available for embed
COPY docs ./cmd/api/docs
RUN go build -o /out/image-mosaic ./cmd/api

### Final runtime image
FROM alpine:3.18
RUN apk add --no-cache ca-certificates
WORKDIR /app
COPY --from=go-builder /out/image-mosaic /app/image-mosaic
EXPOSE 8080
ENV PHOTO_PRISM_BASE_URL=https://photoprism.example.com
ENTRYPOINT ["/app/image-mosaic"]
