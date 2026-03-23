const fs = require('fs');
const path = require('path');
const root = path.join(__dirname, '..');
const files = ['kontakt.html', path.join('en', 'kontakt.html')];

for (const rel of files) {
  const p = path.join(root, rel);
  let c = fs.readFileSync(p, 'utf8');

  c = c.replace(
    /(<a class="contact-link block" href="[^"]+")\s+style="font-size: 13px !important; color: var\(--color-olive\) !important;"/g,
    '$1 style="color: var(--color-olive) !important;"'
  );
  c = c.replace(
    /<span class="block" style="font-size: 13px !important; color: #777 !important;"/g,
    '<span class="block" style="color: #777 !important;"'
  );
  c = c.replace(
    /<span class="block mb-3" style="font-size: 11px !important; color: #999 !important; font-weight: 500; letter-spacing: 0.05em; line-height: 1.35; text-transform: uppercase;"/g,
    '<span class="block mb-3" style="color: #999 !important; font-weight: 500; letter-spacing: 0.05em; line-height: 1.35; text-transform: uppercase;"'
  );
  c = c.replace(
    /<span class="block mb-3" style="font-size: 11px !important; color: #999 !important; font-weight: 500; letter-spacing: 0.05em; text-transform: uppercase;"/g,
    '<span class="block mb-3" style="color: #999 !important; font-weight: 500; letter-spacing: 0.05em; text-transform: uppercase;"'
  );
  c = c.replace(
    /<h3 class="font-serif text-stone-900 font-normal mb-1" style="font-size: 1.25rem;">/g,
    '<h3 class="font-serif text-stone-900 font-normal mb-1">'
  );

  fs.writeFileSync(p, c);
  console.log('Updated', rel);
}
