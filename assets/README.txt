Put your logo here as:  logo.png

- Used by the admin portal (https://api.nad-go.com/) and in all customer/admin emails.
- For emails it is referenced by absolute URL via brand.logo_url in config.php
  (default: https://api.nad-go.com/assets/logo.png), so this file must be
  publicly reachable at that URL.
- Recommended: a horizontal PNG with transparent background, ~240x60px,
  that looks good on the navy header (#0F2057).

Until logo.png exists, the portal hides the broken image and the emails fall
back to the brand name text.
