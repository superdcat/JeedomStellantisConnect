#!/usr/bin/env python3
# UC61 — Extraction des identifiants OAuth2 (client_id/client_secret) depuis l'APK d'une marque
# Stellantis. Remplace la décompression/lecture ZIP faite auparavant en PHP (extensions zip/bz2) : ici
# tout passe par la BIBLIOTHÈQUE STANDARD Python (bz2 + zipfile), donc AUCUNE extension PHP requise et
# AUCUN redémarrage d'Apache après l'installation des dépendances.
#
# Piloté par stellantis::extractCredentialsFromApk() : le PHP télécharge le .apk.bz2, gère les fichiers
# temporaires (création + nettoyage) et les messages utilisateur i18n ; ce script ne fait que
# décompresser (borné anti-bombe), lire deux entrées JSON du zip, et imprimer sur STDOUT une UNIQUE
# ligne JSON : {"status": "...", "client_id": "...", "client_secret": "..."}.
#
# Le secret n'est écrit QUE dans ce JSON (consommé par PHP, jamais loggué). Le script sort toujours en
# code 0 avec un status connu (même en cas d'échec) ; seul un crash Python imprévu part sur stderr.
# SEUL endroit (côté Python) où vivent les chemins internes de l'APK et les noms de champs — à faire
# évoluer ici si Stellantis change la structure de l'APK.

import argparse
import bz2
import json
import sys
import zipfile

CULTURES_ENTRY = 'res/raw/cultures.json'
PARAMS_ENTRY_TPL = 'res/raw-{lang}-r{pays}/parameters.json'
FIELD_CLIENT_ID = 'cvsClientId'
FIELD_CLIENT_SECRET = 'cvsSecret'
MAX_ENTRIES = 100000  # décompte d'entrées aberrant = archive forgée (déni de service à l'énumération)


def _emit(status, client_id='', client_secret=''):
    """Imprime le résultat JSON sur stdout et termine proprement (code 0)."""
    print(json.dumps({'status': status, 'client_id': client_id, 'client_secret': client_secret}))
    sys.exit(0)


def _decompress_bounded(bz2_path, apk_path, max_total):
    """Décompresse bz2_path -> apk_path par blocs, s'interrompt si la sortie dépasse max_total
    (anti-bombe bz2 : petit compressé -> énorme décompressé qui saturerait le disque d'un Raspberry Pi).
    Jamais tout en mémoire. Retourne True si un fichier non vide a été produit dans la limite."""
    total = 0
    try:
        with bz2.open(bz2_path, 'rb') as src, open(apk_path, 'wb') as dst:
            while True:
                chunk = src.read(1048576)
                if not chunk:
                    break
                total += len(chunk)
                if total > max_total:
                    return False
                dst.write(chunk)
    except (OSError, EOFError, ValueError):
        return False
    return total > 0


def _read_json_entry(zf, name, max_entry):
    """Lit une entrée JSON du zip avec garde-fou anti zip-bomb : refus si la taille ANNONCÉE dépasse
    max_entry, puis lecture PLAFONNÉE (au cas où l'en-tête mentirait). Retourne le dict décodé, ou None
    (entrée absente / trop grosse / JSON invalide / pas un objet)."""
    try:
        info = zf.getinfo(name)
    except KeyError:
        return None
    if info.file_size > max_entry:
        return None
    try:
        with zf.open(name, 'r') as f:
            raw = f.read(max_entry + 1)
    except (OSError, zipfile.BadZipFile):
        return None
    if not raw or len(raw) > max_entry:
        return None
    try:
        data = json.loads(raw.decode('utf-8', 'strict'))
    except (ValueError, UnicodeDecodeError):
        return None
    return data if isinstance(data, dict) else None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--bz2', required=True)
    parser.add_argument('--apk', required=True)
    parser.add_argument('--country', required=True)
    parser.add_argument('--max-total', type=int, required=True)
    parser.add_argument('--max-entry', type=int, required=True)
    args = parser.parse_args()

    # 1. Décompression bornée du .bz2 vers le fichier APK.
    if not _decompress_bounded(args.bz2, args.apk, args.max_total):
        _emit('decompress_failed')

    # 2. Ouverture de l'archive (l'APK est un zip).
    try:
        zf = zipfile.ZipFile(args.apk, 'r')
    except (OSError, zipfile.BadZipFile):
        _emit('zip_unreadable')

    try:
        if len(zf.namelist()) > MAX_ENTRIES:
            _emit('zip_unreadable')

        # 3. cultures.json : mappe le pays vers une culture "{lang}_{COUNTRY}" (ex. fr_FR).
        cultures = _read_json_entry(zf, CULTURES_ENTRY, args.max_entry)
        if cultures is None:
            _emit('cultures_missing')

        # cultures.json est indexé en MAJUSCULES (FR) ; repli défensif sur la valeur telle quelle.
        country = args.country.strip()
        culture = None
        for candidate in (country.upper(), country):
            node = cultures.get(candidate)
            if isinstance(node, dict):
                langs = node.get('languages')
                if isinstance(langs, list) and langs and isinstance(langs[0], (str, int, float)):
                    culture = str(langs[0])
                    break
        if culture is None:
            _emit('country_absent')

        # 4. Culture "{lang}_{COUNTRY}" -> res/raw-{lang}-r{COUNTRY}/parameters.json
        parts = culture.split('_')
        if len(parts) < 2 or not parts[0].strip() or not parts[1].strip():
            _emit('culture_invalid')
        entry = PARAMS_ENTRY_TPL.format(lang=parts[0].strip().lower(), pays=parts[1].strip().upper())

        params = _read_json_entry(zf, entry, args.max_entry)
        if params is None:
            _emit('parameters_missing')

        # 5. Extraction des identifiants — jamais de succès partiel (les DEUX doivent être présents).
        client_id = params.get(FIELD_CLIENT_ID)
        client_secret = params.get(FIELD_CLIENT_SECRET)
        client_id = str(client_id).strip() if isinstance(client_id, (str, int, float)) else ''
        client_secret = str(client_secret).strip() if isinstance(client_secret, (str, int, float)) else ''
        if not client_id or not client_secret:
            _emit('credentials_missing')

        _emit('ok', client_id, client_secret)
    finally:
        zf.close()


if __name__ == '__main__':
    try:
        main()
    except SystemExit:
        raise
    except Exception:
        # Jamais de traceback sur stdout (corromprait le JSON attendu par PHP) : détail sur stderr,
        # status générique sur stdout, code 0 pour que PHP retombe sur son message d'échec propre.
        import traceback
        traceback.print_exc(file=sys.stderr)
        print(json.dumps({'status': 'error', 'client_id': '', 'client_secret': ''}))
        sys.exit(0)
