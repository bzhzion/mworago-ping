# Changelog

Toutes les évolutions notables de `mworago-ping` sont documentées ici.

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versionnage
[SemVer](https://semver.org/lang/fr/). La section `[Unreleased]` accumule au fil de l'eau et
est renommée en numéro de version au moment de poser le tag.

Ce fichier est créé le 2026-09-05, après la mise en service : les évolutions antérieures ne sont
pas reconstituées, ce qui serait de la réécriture d'historique plutôt que de la documentation.
L'historique git reste la source de vérité pour ce qui précède.

## [Unreleased]

### Modifié

- **`actions/checkout` et `actions/setup-node` passent en v7** dans les workflows : les versions
  posées déclaraient `using: node20`, déprécié et déjà forcé sur Node 24 par GitHub. Les quatre
  changements de rupture de ces majeures ont été lus et confrontés au parc, aucun ne s'y applique,
  et les 11 runners de l'org sont en 2.336.0 ou mieux, au-dessus du minimum 2.327.1 qu'exigent
  `checkout` v5 et `setup-node` v5. Vérifié par un build iOS réel avant propagation.


### Corrigé

- **Convention de fins de ligne du parc posée dans `.gitattributes`.** Le bloc `run:` d'un
  workflow GitHub Actions est un script shell exécuté sur un runner Linux : un antislash de
  continuation suivi d'un retour chariot **ne continue pas** la ligne, la commande est coupée en
  deux, et le message d'erreur ne parle jamais de fins de ligne.
- Cas réel du 2026-09-07 sur `bzhzion/cabanon` : un `.yml` recommité en CRLF depuis une machine
  Windows (où `core.autocrlf` est actif) a fait échouer le déploiement de l'API sur un
  `usage: ssh`, la destination de la commande ayant disparu avec la continuation.
- LF forcé sur ce qu'exécute Linux (`*.sh`, `*.yml`, `*.yaml`, `Dockerfile`), CRLF sur ce
  qu'exécute Windows (`*.ps1`, `*.bat`, `*.cmd`), et `* text=auto` comme filet général.
  Référence : `admin/.claude/gitattributes-parc`.


### Ajouté

- **Convention changelog du parc posée sur ce dépôt** : ce fichier, les hooks `pre-commit` et
  `pre-push` dans `.githooks/`, et le workflow `changelog-guard.yml` qui rejoue les mêmes
  contrôles en CI au moment du tag. Ce dépôt en était dépourvu alors qu'il est déployé, ce qui
  le laissait hors de la garantie que les autres ont.

