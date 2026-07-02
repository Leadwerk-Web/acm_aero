Image Replacement Notes — 2026-04-03

Summary of actions performed:

- Backup folder created: `backup_images_replace_2026-04-03/` (in repo root)
- Backed up original theme images: `leadwerk_theme/assets/images` -> `backup_images_replace_2026-04-03/leadwerk_theme_images`
- Backed up original importer images: `leadwerk_importer/source_assets/assets/images` -> `backup_images_replace_2026-04-03/leadwerk_importer_images`
- Copied all files from repo root `Fotos/` into:
  - `leadwerk_theme/assets/images/`
  - `leadwerk_importer/source_assets/assets/images/`

Verification:
- Files copied to theme images: 401
- Files copied to importer images: 401
  (counts obtained from automated summary)

Notes and caveats:
- The replacement targeted filenames and directories; some non-image filenames containing the string "Finora" (e.g. `exact-finora-render.php`, `structured-finora-render.php`) remain present in `leadwerk_theme/inc/` and were not removed — these are PHP code files and should be left as-is unless you want renaming.
- All moved/removed files are preserved under `backup_images_replace_2026-04-03/`.

Next steps you may want:
- If you want code-level `finora` filename changes (rename files and update references), I can implement a careful rename and update all references.
- If you want me to remove visible text occurrences of “Finora” inside copied HTML files, I can run a content replace (recommend manual review after).

End of notes.