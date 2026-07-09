# Module OTP (One-Time Password inWebo/PSA) — COPIE VENDORISÉE VERBATIM depuis le projet
# flobz/psa_car_controller (https://github.com/flobz/psa_car_controller), sous-paquet
# psa_car_controller/psa/otp, licence GNU GPL v3.0 (compatible avec la licence GPL-3.0 de ce plugin).
#
# Provenance et attribution : voir resources/THIRD_PARTY_NOTICES.md.
#
# Pourquoi vendoriser plutôt que réimplémenter : l'activation OTP repose sur un protocole
# cryptographique (RSA-OAEP, AES, SHA256, challenge-réponse XML contre https://otp.mpsa.com) dont une
# réimplémentation « from scratch » serait à la fois volumineuse et RISQUÉE — or le quota côté serveur
# est dur (6 codes / 24 h, 20 activations SMS / compte à vie), donc un bug gâche un quota rare.
#
# Version FIGÉE (pas de synchronisation automatique avec l'amont) : ce module ne doit évoluer que sur
# décision explicite, après vérification. Les fichiers (load.py, oaep.py, otp.py, tokenizer.py) sont
# conservés à l'identique de l'amont ; seul ce __init__ porte cette note de provenance.
#
# Utilisé exclusivement par resources/otp_helper.py (jamais importé par le démon MQTT).
