// poznote-panel — minimal control panel for the Poznote TOS package.
//
// It manages the Poznote Docker containers (pull/update, start, stop, restart,
// logs) so the NAS owner never has to open a terminal. The service script
// (init.d/service) starts it next to the containers and passes the paths.
//
// The binary is statically linked (CGO disabled) and cross-compiled for
// linux/amd64 by tools/build_panel.ps1|.sh.
package main

import (
	"bytes"
	"embed"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/exec"
	"sort"
	"strings"
	"time"
)

//go:embed index.html
var uiFS embed.FS

type config struct {
	Listen   string
	Compose  string
	Project  string
	DataDir  string
	Token    string
	ImageTag string
	AppPort  string
}

var cfg config
var startedAt = time.Now()

// composePrefix is ["compose"] for the "docker compose" plugin, or empty when
// the legacy "docker-compose" binary is used.
var composeExe string
var composePrefix []string

func envOr(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func initCompose() error {	if err := exec.Command("docker", "compose", "version").Run(); err == nil {
		composeExe, composePrefix = "docker", []string{"compose"}
		return nil
	}
	if p, err := exec.LookPath("docker-compose"); err == nil {
		composeExe, composePrefix = p, nil
		return nil
	}
	return fmt.Errorf("docker compose is not available")
}

func composeCmd(extra ...string) *exec.Cmd {
	args := make([]string, 0, len(composePrefix)+5+len(extra))
	args = append(args, composePrefix...)
	args = append(args, "-f", cfg.Compose, "--project-name", cfg.Project)
	args = append(args, extra...)
	c := exec.Command(composeExe, args...)
	env := os.Environ()
	if cfg.DataDir != "" {
		env = append(env, "POZNOTE_DATA="+cfg.DataDir)
	}
	if cfg.ImageTag != "" {
		env = append(env, "POZNOTE_IMAGE_TAG="+cfg.ImageTag)
	}
	c.Env = env
	return c
}

// runCombined executes a command, returning combined stdout+stderr and honouring
// a timeout so a hung docker daemon cannot block the panel forever.
func runCombined(c *exec.Cmd, timeout time.Duration) (string, error) {
	var buf bytes.Buffer
	c.Stdout = &buf
	c.Stderr = &buf
	if err := c.Start(); err != nil {
		return buf.String(), err
	}
	done := make(chan error, 1)
	go func() { done <- c.Wait() }()
	select {
	case err := <-done:
		return buf.String(), err
	case <-time.After(timeout):
		if c.Process != nil {
			_ = c.Process.Kill()
		}
		return buf.String(), fmt.Errorf("timeout after %s", timeout)
	}
}

type container struct {
	Name   string `json:"name"`
	Image  string `json:"image"`
	Status string `json:"status"`
}

func containers() ([]container, error) {
	c := exec.Command("docker", "ps", "-a",
		"--filter", "name=poznote",
		"--format", "{{.Names}}\t{{.Image}}\t{{.Status}}")
	out, err := runCombined(c, 20*time.Second)
	if err != nil {
		return nil, err
	}
	var res []container
	for _, line := range strings.Split(strings.TrimSpace(out), "\n") {
		if strings.TrimSpace(line) == "" {
			continue
		}
		p := strings.SplitN(line, "\t", 3)
		for len(p) < 3 {
			p = append(p, "")
		}
		res = append(res, container{Name: p[0], Image: p[1], Status: p[2]})
	}
	sort.Slice(res, func(i, j int) bool { return res[i].Name < res[j].Name })
	return res, nil
}

func writeJSON(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func authorized(r *http.Request) bool {
	if cfg.Token == "" {
		// No token configured: only requests coming from the loopback
		// interface are accepted. This keeps an unconfigured panel from
		// being drivable from the LAN.
		host := r.RemoteAddr
		if i := strings.LastIndex(host, ":"); i >= 0 {
			host = host[:i]
		}
		return host == "127.0.0.1" || host == "::1" || host == "localhost"
	}
	if r.Header.Get("X-Auth-Token") == cfg.Token {
		return true
	}
	return r.URL.Query().Get("token") == cfg.Token
}

func main() {
	flag.StringVar(&cfg.Listen, "listen", "127.0.0.1:8042", "panel listen address")
	flag.StringVar(&cfg.Compose, "compose", "", "path to docker-compose.yml")
	flag.StringVar(&cfg.Project, "project", "poznote", "docker compose project name")
	flag.StringVar(&cfg.DataDir, "data", "", "host data directory passed to compose")
	flag.StringVar(&cfg.Token, "token", os.Getenv("POZNOTE_PANEL_TOKEN"), "optional auth token")
	flag.StringVar(&cfg.ImageTag, "tag", os.Getenv("POZNOTE_IMAGE_TAG"), "image tag (channel)")
	flag.StringVar(&cfg.AppPort, "appport", envOr("POZNOTE_PORT", "8040"), "Poznote web port (for the Open link)")
	flag.Parse()

	if cfg.Compose == "" {
		log.Fatal("-compose is required")
	}

	if err := initCompose(); err != nil {
		log.Printf("WARN: %v", err)
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/", handleIndex)
	mux.HandleFunc("/api/health", func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("ok"))
	})
	mux.HandleFunc("/api/status", handleStatus)
	mux.HandleFunc("/api/logs", handleLogs)
	mux.HandleFunc("/api/action", handleAction)

	log.Printf("poznote-panel listening on %s (project=%s)", cfg.Listen, cfg.Project)
	srv := &http.Server{
		Addr:              cfg.Listen,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
	}
	if err := srv.ListenAndServe(); err != nil {
		log.Fatal(err)
	}
}

func handleIndex(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != "/" {
		http.NotFound(w, r)
		return
	}
	b, err := uiFS.ReadFile("index.html")
	if err != nil {
		http.Error(w, "ui missing", 500)
		return
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("X-Frame-Options", "SAMEORIGIN")
	w.Write(b)
}

func handleStatus(w http.ResponseWriter, r *http.Request) {
	if !authorized(r) {
		writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "unauthorized"})
		return
	}
	cs, err := containers()
	res := map[string]any{
		"panel_uptime": startedAt.Format(time.RFC3339),
		"now":          time.Now().Format(time.RFC3339),
		"uptime":       time.Since(startedAt).Round(time.Second).String(),
		"compose_file": cfg.Compose,
		"data_dir":     cfg.DataDir,
		"project":      cfg.Project,
		"compose_mode": strings.Join(append([]string{composeExe}, composePrefix...), " "),
		"app_port":     cfg.AppPort,
		"containers":   cs,
	}
	if err != nil {
		res["error"] = err.Error()
	}
	writeJSON(w, http.StatusOK, res)
}

func handleLogs(w http.ResponseWriter, r *http.Request) {
	if !authorized(r) {
		writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "unauthorized"})
		return
	}
	tail := r.URL.Query().Get("tail")
	if tail == "" {
		tail = "200"
	}
	out, err := runCombined(composeCmd("logs", "--no-color", "--tail", tail), 30*time.Second)
	res := map[string]any{"logs": out}
	if err != nil {
		res["error"] = err.Error()
	}
	writeJSON(w, http.StatusOK, res)
}

func handleAction(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "POST only"})
		return
	}
	if !authorized(r) {
		writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "unauthorized"})
		return
	}
	action := r.URL.Query().Get("do")
	var out string
	var err error

	switch action {
	case "start":
		out, err = runCombined(composeCmd("up", "-d", "--remove-orphans"), 5*time.Minute)
	case "stop":
		out, err = runCombined(composeCmd("stop"), 2*time.Minute)
	case "restart":
		out, err = runCombined(composeCmd("restart"), 2*time.Minute)
	case "update":
		// Pull the newest image for both containers, then recreate them.
		pullOut, pullErr := runCombined(composeCmd("pull"), 30*time.Minute)
		upOut, upErr := runCombined(composeCmd("up", "-d", "--remove-orphans"), 5*time.Minute)
		out = pullOut + "\n" + upOut
		err = pullErr
		if err == nil {
			err = upErr
		}
	default:
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "unknown action"})
		return
	}

	res := map[string]any{"action": action, "output": out, "ok": err == nil}
	if err != nil {
		res["error"] = err.Error()
	}
	writeJSON(w, http.StatusOK, res)
}
