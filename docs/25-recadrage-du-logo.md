# 25 — Shop logo: crop, zoom and preview before saving

Focused improvement of the existing logo upload (Settings → Profile, owner only). Same route, same rules, same stored files and
display everywhere; only the step between "choose an image" and "save" changed.

## Flow

1. **Choose or drop** a PNG/JPG on the logo area. It is checked immediately with the existing rules: PNG or JPEG, 2 MB maximum,
   200 × 200 px minimum (6 000 × 6 000 px maximum, the server's existing ceiling, now also checked on screen). A refusal shows a
   clear message and nothing else happens.
2. **Crop-and-adjust**: a square work area with a circular guide (the logo is shown round in the menu and the profile).
   - Drag the image (mouse or finger); pinch with two fingers; Ctrl/⌘ + wheel or trackpad pinch to zoom.
   - Zoom slider and `+` / `−` buttons, from "whole image visible" (letterboxed) up to ×4. Keyboard: arrows move (Shift: faster),
     `+` / `−` zoom, `0` resets; the area is focusable and described for screen readers.
   - **Reset** returns to the original framing. The aspect ratio is never changed: both axes use the same scale.
   - **Live previews** at real size: menu (32 px circle with the shop name), profile (88 px circle) and the centre of the QR code
     (square). All come from the same crop.
3. **Save / Cancel**. Nothing is sent before Save. The browser draws the chosen framing into a 512 × 512 PNG (white margins for a
   JPEG, transparent for a PNG) and uploads it to the usual endpoint. Cancel discards the working copy.

## What the server does (unchanged rules, safer write)

- Re-validates type, size and dimensions, re-encodes (metadata stripped) and creates the 64 px (menu) and 256 px (profile, QR)
  PNGs from the square, with high-quality downscaling — no stretching, and at least 2× the displayed size so nothing is pixelated.
- **Failure keeps the previous logo**: both sizes are encoded in memory first; if a write fails, files already replaced are put
  back and the logo version is not updated. The form shows "Saving failed. Your current logo is kept." and the crop stays open
  so the user can retry. A non-square upload received directly is still centre-cropped.

## Decisions

- The platform shows the logo in one asset shape (a square, masked to a circle in the app, square in the QR code), so a single
  fitted square is stored, as before. The original upload is **not** kept: re-adjusting a saved logo means choosing the file again.
  (Keeping the original would be a storage/privacy decision for later.)
- Output is capped at 2 MB (falls back to 384 then 256 px if a heavy image would exceed it).

Tests: `LogoCropper`, `LogoUploader`, `logoCrop` (Vitest); `tests/Feature/Tenancy/OrganizationLogoTest.php` (cropped upload kept
as framed at both sizes, write failure and unreadable image leave the saved logo untouched).
