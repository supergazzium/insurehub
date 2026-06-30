/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Base URL for the Laravel API, e.g. `http://127.0.0.1:8000/api/v1`. */
  readonly VITE_API_BASE_URL?: string
  /** DeepSeek key — used by `useDeepseekApi.ts` when configured. */
  readonly VITE_DEEPSEEK_API_KEY?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
