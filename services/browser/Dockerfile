# Playwright's official image ships Chromium + all system deps at a matching
# version, so there's nothing to install for the browser itself.
FROM mcr.microsoft.com/playwright:v1.48.0-jammy

WORKDIR /app

COPY package.json ./
RUN npm install --omit=dev

COPY server.js ./

EXPOSE 3000
CMD ["node", "server.js"]
