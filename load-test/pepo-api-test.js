import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost/PEPO';

const apiDuration = new Trend('api_duration');
const apiErrorRate = new Rate('api_errors');

export const options = {
  scenarios: {
    functional: {
      executor: 'constant-vus',
      vus: 5,
      duration: '1m',
    },
    peak: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 20 },
        { duration: '1m', target: 20 },
        { duration: '30s', target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<1000'],
    http_req_failed: ['rate<0.05'],
  },
};

function getSessionCookie(email, password) {
  const loginRes = http.post(`${BASE_URL}/process.php`, {
    email: email,
    password: password,
  });

  const cookies = loginRes.cookies;
  const sessionCookie = Object.values(cookies).flat().find(c => c.name === 'app_session');

  return sessionCookie?.value || null;
}

export const setup = () => {
  const sessionId = getSessionCookie('admin@pepo.local', 'admin123');
  return { sessionId };
};

export default function (data) {
  const params = {
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
  };

  if (data.sessionId) {
    params.cookies = { app_session: data.sessionId };
  }

  const endpoints = [
    { url: '/admin_dashboard.php', name: 'admin_dashboard' },
    { url: '/fetch_activities.php', name: 'fetch_activities' },
    { url: '/fetch_notifications.php', name: 'fetch_notifications' },
    { url: '/profile.php', name: 'profile' },
  ];

  const endpoint = endpoints[Math.floor(Math.random() * endpoints.length)];

  const startTime = Date.now();
  const res = http.get(`${BASE_URL}${endpoint.url}`, params);
  apiDuration.add(Date.now() - startTime);

  apiErrorRate.add(res.status !== 200);

  check(res, {
    [`${endpoint.name} loaded`]: (r) => r.status === 200 || r.status === 302,
  });

  sleep(Math.random() * 2 + 0.5);
}

export function handleSummary(data) {
  return {
    'stdout': JSON.stringify(data, null, 2),
  };
}