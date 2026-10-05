# syntax=docker/dockerfile:1

# ---- 建置階段 ----
FROM golang:1.27-alpine AS build
WORKDIR /src
# 先只複製依賴清單，原始碼改動時不必重新下載依賴
# go.sum 在還沒有外部依賴時不存在，用萬用字元讓它可有可無
COPY go.mod go.sum* ./
RUN go mod download
COPY . .
# CGO_ENABLED=0 產生靜態連結的執行檔，才能放進 distroless/static
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/server ./cmd/server

# ---- 執行階段 ----
FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /out/server /server
EXPOSE 8080
USER nonroot:nonroot
ENTRYPOINT ["/server"]
