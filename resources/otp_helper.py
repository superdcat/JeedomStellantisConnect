#!/usr/bin/env python3
# UC12 — Helper OTP one-shot piloté par stellantis::runOtpHelper() (PHP).
#
# Confine la partie CRYPTOGRAPHIQUE de l'activation OTP (module vendorisé resources/otp_vendor, protocole
# inWebo/PSA contre https://otp.mpsa.com) à un appel Python jetable : PHP fait tout le reste (REST OAuth2,
# stockage chiffré, quotas, i18n). Deux actions :
#   - activate {sms, pin}   -> provisionne un device + génère un 1er code OTP
#   - code     {device_secret} -> génère un code OTP à partir du device déjà provisionné (sans SMS)
#
# PROTOCOLE : requête JSON sur STDIN, réponse JSON (une ligne) sur STDOUT. On n'utilise JAMAIS argv :
# sms / pin / device_secret sont sensibles et argv est visible via `ps`. STDIN ne l'est pas.
# Le device provisionné est renvoyé sérialisé (base64 d'un pickle) dans `device_secret` : PHP le stocke
# CHIFFRÉ et le repasse tel quel à l'action `code`. Il est aussi renvoyé après chaque `code` (l'état
# roule côté serveur). pickle.loads n'est appliqué QU'À notre propre blob (issu du cache chiffré Jeedom),
# jamais à une donnée tierce.
#
# Le script sort toujours en code 0 avec un `status` connu (ok / bad_input / deps_missing /
# device_invalid / otp_error / error). Rien de sensible n'est écrit sur STDOUT hors le code OTP attendu
# par PHP ; un éventuel traceback part sur STDERR (ignoré par PHP). Le logging est coupé (le module
# vendorisé logue en debug le code/les clés — jamais laissé s'échapper).

import base64
import json
import logging
import os
import pickle
import sys

logging.disable(logging.CRITICAL)


def _emit(status, **extra):
    """Imprime le résultat JSON sur stdout et termine proprement (code 0)."""
    out = {'status': status}
    out.update(extra)
    print(json.dumps(out))
    sys.exit(0)


def _dump_device(otp):
    """Sérialise l'objet device provisionné en base64(pickle) — consommé chiffré par PHP."""
    return base64.b64encode(pickle.dumps(otp)).decode('ascii')


def main():
    # 1. Requête sur STDIN (jamais argv).
    try:
        raw = sys.stdin.read()
        request = json.loads(raw) if raw.strip() else {}
    except (ValueError, OSError):
        _emit('bad_input')
    if not isinstance(request, dict):
        _emit('bad_input')
    action = request.get('action')

    # 2. Import du module vendorisé ICI (pas au niveau module) : si pycryptodomex/requests manquent, on
    # renvoie un status propre plutôt qu'un crash illisible. Le dossier du script est ajouté au sys.path
    # de façon STABLE → le chemin de module inscrit dans le pickle (otp_vendor.*) est reproductible d'un
    # process à l'autre (indispensable pour recharger le device à l'action `code`).
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    try:
        import otp_vendor.otp as otpmod
    except Exception:
        _emit('deps_missing')

    # 3. Neutralise l'écriture de otp.bin dans le CWD (= CWD d'Apache/PHP) : on sérialise nous-mêmes,
    # rien sur le disque.
    otpmod.save_otp = lambda *a, **k: None

    try:
        if action == 'activate':
            sms = str(request.get('sms', '')).strip()
            pin = str(request.get('pin', '')).strip()
            if not sms or not pin:
                _emit('bad_input')
            otp = otpmod.new_otp_session(sms, pin)
            code = otp.get_otp_code()
            if not code:
                _emit('otp_error')
            _emit('ok', otp_code=code, device_secret=_dump_device(otp))
        elif action == 'code':
            blob = request.get('device_secret')
            if not blob:
                _emit('bad_input')
            try:
                otp = pickle.loads(base64.b64decode(blob))
            except Exception:
                _emit('device_invalid')
            code = otp.get_otp_code()
            if not code:
                _emit('otp_error')
            _emit('ok', otp_code=code, device_secret=_dump_device(otp))
        else:
            _emit('bad_input')
    except otpmod.ConfigException:
        # Rejet côté serveur OTP (code SMS/PIN faux, quota, device expiré…).
        _emit('otp_error')
    except Exception:
        # Réseau/timeout requests, réponse serveur inattendue…
        _emit('otp_error')


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
        print(json.dumps({'status': 'error'}))
        sys.exit(0)
