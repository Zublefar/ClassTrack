/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    "./index.html",
    "./*.php"
  ],
  theme: {
    extend: {
      colors: {
        brand: { 50: '#f0fdf4', 100: '#dcfce7', 500: '#14b8a6', 600: '#0d9488', 700: '#0f766e', accent: '#38bdf8' }
      }
    }
  },
  plugins: [],
}
