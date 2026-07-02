/* ACM AIR CHARTER - Tailwind CSS Configuration */
tailwind.config = {
      theme: {
        extend: {
          maxWidth: {
            '6xl': '100rem',
          },
          colors: {
            cream: '#FAF9F7',
            olive: {
              DEFAULT: '#001441',
              dark: '#000d2b',
              light: '#0a2a5c',
              lighter: '#E8EEF4'
            },
            accent: {
              DEFAULT: '#001441',
              hover: '#000d2b',
              light: '#E8EEF4'
            },
            sand: {
              50: '#FDFCFA',
              100: '#FAF8F5',
              200: '#F5F2ED'
            }
          },
          fontFamily: {
            serif: ['Cormorant Garamond', 'Georgia', 'serif'],
            sans: ['Inter', 'system-ui', 'sans-serif']
          },
          letterSpacing: {
            'wider-custom': '0.15em'
          }
        }
      }
    }
