/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#e6f7f7',
          100: '#b2e5e5',
          200: '#80d2d1',
          300: '#4dbfbe',
          400: '#26b1af',
          500: '#026766',
          600: '#025958',
          700: '#014f4e',
          800: '#013d3c',
          900: '#012d2c',
        },
        'brand-primary': '#025958',
        'surface-card': '#ffffff',
        'text-muted': '#6b7280',
        'border-subtle': '#e5e7eb',
      },
      spacing: {
        'page-x': '1rem',
        'page-y': '1.5rem',
        'card-p': '1rem',
        'section-gap': '1.25rem',
        'list-gap': '0.75rem',
      },
      fontSize: {
        'kpi-value': ['1.5rem', { lineHeight: '2rem', fontWeight: '800' }],
        'kpi-label': ['0.6875rem', { lineHeight: '1rem', fontWeight: '700', letterSpacing: '0.05em', textTransform: 'uppercase' }],
        'table-header': ['0.6875rem', { lineHeight: '1rem', fontWeight: '700', letterSpacing: '0.05em', textTransform: 'uppercase' }],
        'page-title': ['1.25rem', { lineHeight: '1.75rem', fontWeight: '700' }],
        'body-sm': ['0.875rem', { lineHeight: '1.25rem' }],
      },
      fontFamily: {
        sans: ['Inter', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
