# The web container of the production stack: the built SPA and the API behind
# one origin, so the browser makes no cross-origin request and the bearer token
# is the only credential in play.
#
# Build context is the repository root — the image needs frontend/ and the
# static assets of backend/public.

FROM node:22-alpine AS spa

WORKDIR /app
COPY frontend/package.json frontend/package-lock.json frontend/.npmrc ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM nginx:1.29-alpine

COPY docker/nginx/limits.conf /etc/nginx/conf.d/00-limits.conf
COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/laravel.conf /etc/nginx/laravel.conf
COPY --from=spa /app/dist /usr/share/nginx/html
# Swagger UI is vendored and served as files; the page that loads it is a
# Laravel route.
COPY backend/public/swagger-ui /var/www/html/public/swagger-ui
