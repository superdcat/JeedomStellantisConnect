# Index mémoire

- [Skill `dev` indisponible en session](feedback_dev_skill_unavailable.md) — l'outil Skill échoue sur "dev" malgré le system prompt ; ne pas retenter, suivre directement la méthodologie inline (cadrer→incréments→vérifier→auto-revue).
- [Vérif PHP sans `php -l` local](feedback_no_local_php_verification.md) — pas de binaire php dans ce shell ; script Python (strip commentaires/strings + équilibrage {}()[]) via fichier Write (pas de heredoc bash, casse les backslash) pour sécuriser un gros diff avant remise.
- [Edit tool échoue sur fichiers indentés en tabs](feedback_edit_tool_tab_indented_files.md) — desktop/php/*.php = tabs+CRLF, retyper l'indentation à la main ne matche pas ; dériver l'indentation réelle via script Python (regex sur le fichier) plutôt que de réessayer Edit en boucle.
