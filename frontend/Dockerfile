# syntax=docker/dockerfile:1
# ---------------------------------------------------------------------------
# Surgical Devices ERP — React PWA image
# Build the static bundle with Node, then serve it via nginx with API proxying
# and SPA history fallback.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM nginx:1.27-alpine AS serve
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
