import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost/PEPO';
const ADMIN_EMAIL = __ENV.ADMIN_EMAIL || 'admin@pepo.local';
const ADMIN_PASSWORD = __ENV.ADMIN_PASSWORD || 'admin123';

const loginDuration = new Trend('login_duration');
const loginErrorRate = new Rate('login_errors');

export const options = {
  scenarios: {
    smoke: {
      executor: 'constant-vus',
      vus: 1,
      duration: '30s',
    },
    load: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 10 },
        { duration: '1m', target: 10 },
        { duration: '30s', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
    stress: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '1m', target: 20 },
        { duration: '2m', target: 50 },
        { duration: '1m', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<500'],
    http_req_failed: ['rate<0.1'],
  },
};

export function setup() {
  const loginRes = http.post(`${BASE_URL}/process.php`, {
    email: ADMIN_EMAIL,
    password: ADMIN_PASSWORD,
  });

  const cookies = loginRes.cookies;
  const sessionCookie = Object.values(cookies).flat().find(c => c.name === 'app_session');

  return {
    sessionId: sessionCookie?.value || null,
  };
}

export default function (data) {
  const params = {
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
  };

  if (data.sessionId) {
    params.cookies = { app_session: data.sessionId };
  }

  const startTime = Date.now();

  const res = http.get(`${BASE_URL}/admin_dashboard.php`, params);

  loginDuration.add(Date.now() - startTime);
  loginErrorRate.add(res.status !== 200);

  check(res, {
    'dashboard loaded': (r) => r.status === 200,
    'response time < 500ms': (r) => r.timings.duration < 500,
  });

  sleep(1);
}

export function handleSummary(data) {
  return {
    'stdout': textSummary(data, { indent: ' ', enableColors: true }),
    'summary.json': JSON.stringify(data, null, 2),
  };
}

function textSummary(data, options) {
  const indent = options.indent || '';
  let output = '\n' + indent + '=== k6 Load Test Results ===\n\n';

  const metrics = data.metrics.http_req_duration;
  if (metrics) {
    output += indent + 'Response Time (ms):\n';
    output += indent + `  avg: ${metrics.values.avg.toFixed(2)}\n`;
    output += indent + `  p95: ${metrics.values['p(95)'].toFixed(2)}\n`;
    output += indent + `  max: ${metrics.values.max.toFixed(2)}\n\n`;
  }

  const requests = data.metrics.http_reqs;
  if (requests) {
    output += indent + `Total Requests: ${requests.values.count}\n`;
    output += indent + `Request Rate: ${requests.values.rate.toFixed(2)}/s\n\n`;
  }

  const failures = data.metrics.http_req_failed;
  if (failures) {
    output += indent + `Failed Requests: ${(failures.values.passes * failures.values.rate).toFixed(0)}\n`;
    output += indent + `Failure Rate: ${(failures.values.rate * 100).toFixed(2)}%\n`;
  }

  return output;
}