// Command server 是 Go 版短網址 API 的進入點。
// 目前只有健康檢查；轉址與管理 API 在開發順序的階段二加入。
package main

import (
	"log"
	"net/http"
)

func main() {
	mux := http.NewServeMux()

	// 兩個後端都在回應加上 X-Backend，Nginx 把它們放在同一個 upstream 時才分得出是誰回的
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("X-Backend", "go")
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		w.Write([]byte("ok\n"))
	})

	log.Println("listening on :8080")
	log.Fatal(http.ListenAndServe(":8080", mux))
}
