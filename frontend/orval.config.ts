import { defineConfig } from 'orval'

/**
 * The client is generated from the contract, not written beside it.
 *
 * `backend/api/openapi.yaml` is the single description of the API (§3.3.6), and
 * everything under `src/api/generated` is its output: request functions, the
 * TanStack Query hooks that wrap them and the TypeScript models. That folder is
 * never edited by hand — `npm run api:generate` overwrites it. Every request
 * goes through the mutator in `src/api/http-client.ts`, which is where the base
 * path, the bearer token and the reaction to a rejected token live.
 */
export default defineConfig({
  dormitory: {
    input: {
      target: '../backend/api/openapi.yaml',
    },
    output: {
      mode: 'split',
      target: 'src/api/generated/dormitory.ts',
      schemas: 'src/api/generated/model',
      client: 'react-query',
      httpClient: 'fetch',
      override: {
        mutator: {
          path: 'src/api/http-client.ts',
          name: 'apiFetch',
        },
        query: {
          signal: true,
        },
      },
    },
  },
})
