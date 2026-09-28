/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        roi: {
          dark: '#0F172A',
          primary: '#0284C7',
          secondary: '#F59E0B',
          accent: '#10B981',
          coral: '#E11D48',
          light: '#F8FAFC'
        }
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
      }
    },
  },
  plugins: [],
}
