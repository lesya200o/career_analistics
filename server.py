#!/usr/bin/env python3
from __future__ import annotations

import json
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, unquote, urlparse

ROOT = Path(__file__).resolve().parent
RESULTS_DIR = ROOT / "results"


def build_specialities() -> list[dict]:
    specialities: list[dict] = []
    for path in sorted(RESULTS_DIR.glob("unified_*.json")):
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            continue

        metadata = data.get("metadata", {})
        stats = data.get("statistics", {})
        specialities.append(
            {
                "id": path.stem,
                "title": metadata.get("search_query", path.stem),
                "fileName": path.name,
                "totalVacancies": int(stats.get("total", 0) or 0),
                "generatedAt": metadata.get("generated_at"),
            }
        )

    specialities.sort(key=lambda item: str(item.get("title", "")))
    return specialities


class AppHandler(SimpleHTTPRequestHandler):
    def do_GET(self) -> None:
        parsed = urlparse(self.path)
        route = parsed.path
        query = parse_qs(parsed.query)

        if route in {"/api/specialities", "/api/specialities/"}:
            payload = build_specialities()
            body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return

        if route in {"/api/speciality", "/api/speciality/"}:
            speciality_id = (query.get("id") or [""])[0].strip()
            if not speciality_id:
                self._send_json(400, {"error": "id query param is required"})
                return

            file_path = (RESULTS_DIR / f"{speciality_id}.json").resolve()
            if file_path.parent != RESULTS_DIR.resolve() or not file_path.exists():
                self._send_json(404, {"error": "speciality file not found"})
                return

            try:
                data = json.loads(file_path.read_text(encoding="utf-8"))
            except Exception:
                self._send_json(500, {"error": "failed to read speciality file"})
                return

            self._send_json(200, data)
            return

        if route.startswith("/api/specialities/") and route != "/api/specialities/":
            speciality_id = unquote(route.rsplit("/", 1)[-1]).strip()
            if speciality_id.endswith(".json"):
                speciality_id = speciality_id[:-5]
            if not speciality_id:
                self._send_json(400, {"error": "id is required"})
                return

            file_path = (RESULTS_DIR / f"{speciality_id}.json").resolve()
            if file_path.parent != RESULTS_DIR.resolve() or not file_path.exists():
                self._send_json(404, {"error": "speciality file not found"})
                return

            try:
                data = json.loads(file_path.read_text(encoding="utf-8"))
            except Exception:
                self._send_json(500, {"error": "failed to read speciality file"})
                return

            self._send_json(200, data)
            return

        super().do_GET()

    def log_message(self, format: str, *args: object) -> None:
        return

    def _send_json(self, status: int, payload: dict | list) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


def main() -> None:
    server = ThreadingHTTPServer(("127.0.0.1", 4173), AppHandler)
    print("Server started: http://127.0.0.1:4173")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
