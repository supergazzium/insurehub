/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{vue,js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['"IBM Plex Sans Thai"', '"Sarabun"', 'system-ui', 'sans-serif'],
      },
      colors: {
        // Brand teal — derived from the InsureHub logo
        brand: {
          50:  '#ecfbfc',
          100: '#cef3f6',
          200: '#a3e7ed',
          300: '#6dd4dd',
          400: '#3ebac6',
          500: '#26a4b0',
          600: '#1f8893',
          700: '#1d6e78',
          800: '#1d5a63',
          900: '#0f3e44',
        },
        // Accent amber/orange — from the logo's "Hub" wordmark
        accent: {
          50:  '#fff8eb',
          100: '#ffe8c2',
          200: '#ffd388',
          300: '#ffb14f',
          400: '#fb9326',
          500: '#f37810',
          600: '#d75d09',
          700: '#b2440a',
          800: '#8e370f',
          900: '#742f10',
        },
      },
    },
  },
  plugins: [],
}
