/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{js,jsx,ts,tsx}', './public/index.html'],
  theme: {
    extend: {
      colors: {
        // WeTransfer-inspired teal accent
        brand: {
          50:  '#e6faf8',
          100: '#b3f0ea',
          200: '#80e5db',
          300: '#4ddacc',
          400: '#1acfbd',
          500: '#00bfae',
          600: '#009989',
          700: '#007365',
          800: '#004c43',
          900: '#002621',
        },
      },
    },
  },
  plugins: [require('@tailwindcss/forms')],
};
