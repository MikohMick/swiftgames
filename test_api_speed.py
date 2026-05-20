#!/usr/bin/env python3
"""
Wupex API Endpoint Speed Test
Tests response times for all 4 API endpoints across sandbox and production environments.
"""

import subprocess
import json
import statistics
import sys
from datetime import datetime

ENVIRONMENTS = {
    "Sandbox":    "https://sandbox-service.wupex.com",
    "Production": "https://service.wupex.com",
}

ENDPOINTS = [
    {
        "name":   "GET  /api/customer/balance",
        "method": "GET",
        "path":   "/api/customer/balance",
        "body":   None,
    },
    {
        "name":   "POST /api/product/merchant/invited/list",
        "method": "POST",
        "path":   "/api/product/merchant/invited/list",
        "body":   json.dumps({"page": 1, "pageSize": 50}),
    },
    {
        "name":   "POST /api/order/pull-codes",
        "method": "POST",
        "path":   "/api/order/pull-codes?referenceId=speedtest&allowTakeAll=true",
        "body":   json.dumps([{"merchant": "test", "sku": "test-sku", "quantity": 1}]),
    },
    {
        "name":   "GET  /api/order/detail",
        "method": "GET",
        "path":   "/api/order/detail?orderName=speedtest-001",
        "body":   None,
    },
]

RUNS = 3  # number of timed runs per endpoint

CURL_WRITE_OUT = (
    "%{time_namelookup}|"
    "%{time_connect}|"
    "%{time_appconnect}|"
    "%{time_starttransfer}|"
    "%{time_total}|"
    "%{http_code}|"
    "%{size_download}"
)

HEADERS = [
    "-H", "x-api-key: dummy-key-for-speed-test",
    "-H", "Content-Type: application/json",
    "-H", "Accept: application/json",
    "-H", "Accept-Language: en",
    "-H", "User-Agent: WupexSpeedTest/1.0",
]


def curl_request(method: str, url: str, body: str | None) -> dict:
    cmd = [
        "curl", "-s", "-o", "/dev/null",
        "--max-time", "30",
        "--connect-timeout", "10",
        "-w", CURL_WRITE_OUT,
        "-X", method,
    ]
    cmd.extend(HEADERS)
    if body:
        cmd.extend(["--data", body])
    cmd.append(url)

    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=35)
        parts = result.stdout.strip().split("|")
        if len(parts) != 7:
            return {"error": f"unexpected curl output: {result.stdout!r}"}
        return {
            "dns_ms":    round(float(parts[0]) * 1000, 1),
            "connect_ms": round(float(parts[1]) * 1000, 1),
            "tls_ms":    round(float(parts[2]) * 1000, 1),
            "ttfb_ms":   round(float(parts[3]) * 1000, 1),
            "total_ms":  round(float(parts[4]) * 1000, 1),
            "http_code": parts[5],
            "bytes":     int(parts[6]),
        }
    except subprocess.TimeoutExpired:
        return {"error": "timeout"}
    except Exception as exc:
        return {"error": str(exc)}


def run_tests() -> list[dict]:
    results = []
    total_tests = len(ENVIRONMENTS) * len(ENDPOINTS) * RUNS
    done = 0

    for env_name, base_url in ENVIRONMENTS.items():
        for ep in ENDPOINTS:
            url = base_url + ep["path"]
            timings = []
            http_code = "-"
            error = None

            for run in range(1, RUNS + 1):
                done += 1
                print(f"  [{done:2d}/{total_tests}] {env_name} | {ep['name']} (run {run}/{RUNS})...", flush=True)
                r = curl_request(ep["method"], url, ep["body"])
                if "error" in r:
                    error = r["error"]
                    break
                timings.append(r["total_ms"])
                http_code = r["http_code"]
                last = r

            if error:
                results.append({
                    "env":      env_name,
                    "endpoint": ep["name"],
                    "avg_ms":   "-",
                    "status":   "Error ❌",
                })
            else:
                avg = statistics.mean(timings)
                results.append({
                    "env":      env_name,
                    "endpoint": ep["name"],
                    "avg_ms":   f"{avg:.0f}",
                    "status":   speed_rating(avg),
                })
    return results


def speed_rating(avg_ms: float) -> str:
    if avg_ms < 300:
        return "Fast ✅"
    if avg_ms < 800:
        return "OK ⚠️"
    if avg_ms < 1500:
        return "Slow ❌"
    return "Very Slow ❌"


def print_table(results: list[dict]) -> None:
    # Simple friendly names for endpoints
    friendly = {
        "GET  /api/customer/balance":              "Check Balance",
        "POST /api/product/merchant/invited/list": "List Products",
        "POST /api/order/pull-codes":              "Pull Order Codes",
        "GET  /api/order/detail":                  "Get Order Detail",
    }

    cols = [
        ("Where",    "env",      12),
        ("What",     "label",    18),
        ("Speed",    "avg_ms",    9),
        ("Result",   "status",   12),
    ]

    sep    = "+" + "+".join("-" * (w + 2) for _, _, w in cols) + "+"
    header = "|" + "|".join(f" {h:<{w}} " for h, _, w in cols) + "|"

    print()
    print(f"  Wupex API Speed Test  —  {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print()
    print(sep)
    print(header)
    print(sep)

    prev_env = None
    for row in results:
        if prev_env and row["env"] != prev_env:
            print(sep)
        prev_env = row["env"]
        display = {
            "env":    row["env"],
            "label":  friendly.get(row["endpoint"], row["endpoint"]),
            "avg_ms": f"{row['avg_ms']} ms" if row["avg_ms"] != "-" else "-",
            "status": row["status"],
        }
        line = "|" + "|".join(f" {str(display[k]):<{w}} " for _, k, w in cols) + "|"
        print(line)

    print(sep)
    print()
    print("  Speed = average response time over 3 test runs")
    print("  Fast ✅ = under 300ms  |  OK ⚠️ = under 800ms  |  Slow/Very Slow ❌ = 800ms+")
    print()


if __name__ == "__main__":
    print()
    print("=" * 70)
    print("  Wupex API Endpoint Speed Test")
    print(f"  Environments: Sandbox + Production   |   Runs per endpoint: {RUNS}")
    print("=" * 70)
    print()

    results = run_tests()
    print_table(results)
