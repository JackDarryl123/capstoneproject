# PEPO Load Testing with k6

## Install k6

```bash
# Windows (using winget)
winget install k6 --source winget

# Or download from https://github.com/grafana/k6/releases
```

## Run Tests

### Smoke Test (quick validation)
```bash
k6 run --env BASE_URL=http://localhost/PEPO load-test/login-test.js
```

### Load Test
```bash
k6 run --env BASE_URL=http://localhost/PEPO load-test/login-test.js --out json=results.json
```

### Stress Test
```bash
k6 run --env BASE_URL=http://localhost/PEPO load-test/login-test.js --env SCENARIO=stress
```

### API Test
```bash
k6 run --env BASE_URL=http://localhost/PEPO load-test/pepo-api-test.js
```

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| BASE_URL | http://localhost/PEPO | Application URL |
| ADMIN_EMAIL | admin@pepo.local | Admin email for testing |
| ADMIN_PASSWORD | admin123 | Admin password |

## Test Scenarios

- **smoke**: 1 VU for 30s - Quick validation
- **load**: 10 VUs ramp up - Normal load testing
- **stress**: 50 VUs - Stress testing

## Output Formats

```bash
# JSON output
k6 run test.js --out json=results.json

# InfluxDB/Grafana
k6 run test.js --out influxdb=http://localhost:8086/k6
```