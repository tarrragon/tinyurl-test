// Command server 是 Go 版短網址 API 的進入點。
// 目前只有健康檢查；轉址與管理 API 在開發順序的階段二加入。
package main

import (
	"flag"
	"log"
	"net/http"
	"os"
	"time"
)

const addr = ":8080"

func main() {
	// 執行階段的映像檔是 distroless，沒有 shell 也沒有 curl，Docker 的 healthcheck
	// 沒有工具可以呼叫；所以同一支執行檔帶 -healthcheck 時改當健康檢查的客戶端用。
	healthcheck := flag.Bool("healthcheck", false, "請求本機的 /-/healthz，成功時結束碼 0，失敗時 1")
	flag.Parse()
	if *healthcheck {
		os.Exit(checkHealth())
	}

	mux := http.NewServeMux()

	// 兩個後端都在回應加上 X-Backend，Nginx 把它們放在同一個 upstream 時才分得出是誰回的
	mux.HandleFunc("GET /-/healthz", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("X-Backend", "go")
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		w.Write([]byte("ok\n"))
	})

	log.Println("listening on", addr)
	log.Fatal(http.ListenAndServe(addr, mux))
}

// checkHealth 對同一個容器裡的伺服器發一次 /-/healthz，回傳給 os.Exit 的結束碼。
// 逾時要比 compose healthcheck 的 timeout 短，否則 Docker 會先判逾時，看不到這裡的錯誤訊息。
func checkHealth() int {
	client := http.Client{Timeout: 2 * time.Second}
	resp, err := client.Get("http://127.0.0.1" + addr + "/-/healthz")
	if err != nil {
		log.Println("healthcheck:", err)
		return 1
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		log.Println("healthcheck: status", resp.StatusCode)
		return 1
	}
	return 0
}
