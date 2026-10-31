# NKCosmetics

A YAML-driven, resource-pack-powered cosmetics plugin for **PocketMine-MP API 5** and
**Axolotl-PM**, by NinjaKnights.

Players can wear one cosmetic in each of seven categories at the same time:

| Category | What it is | Who defines it |
| --- | --- | --- |
| Hats | 3D model on the head, follows head rotation | You, in `cosmetics/hats.yml` |
| Back | 3D model on the back (wings, backpacks, capes) | You, in `cosmetics/back.yml` |
| Offhand | 3D model held in the left hand, swings with the arm | You, in `cosmetics/offhand.yml` |
| Balloons | Floating balloon on a lead that follows the player | You, in `cosmetics/balloons.yml` |
| Pets | Baby vanilla mobs that follow, sit and wander | Built in |
| Particles | Auras and walking trails | Built in |
| Gadgets | Right-click items with harmless visual effects | Built in |

Server owners add hats, back items, offhand items and balloons with a YAML entry and a model in the
resource pack: no PHP needed. Pets, particles and gadgets are fixed catalogs implemented in PHP;
they can be renamed, disabled, re-permissioned or unlocked for everyone, but not extended.

Cosmetics never affect gameplay: they do not touch armour or inventory slots (except the gadget
tool, see [Gadgets](#gadgets)), have no collision and deal no damage.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Commands](#commands)
- [Permissions and unlocking](#permissions-and-unlocking)
- [Configuration](#configuration)
- [Adding your own cosmetics](#adding-your-own-cosmetics)
- [The resource pack](#the-resource-pack)
- [Built-in pets, particles and gadgets](#built-in-pets-particles-and-gadgets)
- [How skin cosmetics work](#how-skin-cosmetics-work)
- [Developer API](#developer-api)
- [Compatibility and known limitations](#compatibility-and-known-limitations)
- [Development](#development)
- [License](#license)

## Requirements

- PocketMine-MP 5 or Axolotl-PM 5 (tested on Axolotl-PM 5.51.0, Bedrock 1.26.60).
- PHP extensions `gd`, `sqlite3`, `zip` and `yaml`. The official PocketMine/Axolotl PHP binaries
  include all of them.
- No other plugins. The forms library (pmforms) is bundled inside the PHAR.

## Installation

1. Download `NKCosmetics.phar` from the plugin's BedrockNexus page or GitHub releases and put it in
   your server's `plugins/` folder.
2. Start the server. NKCosmetics creates its files in `plugin_data/NKCosmetics/`:

   ```text
   plugin_data/NKCosmetics/
   ├── config.yml           global settings
   ├── cosmetics/           one file per cosmetic type (see Configuration)
   ├── resource-pack/       editable models, textures, animations and balloon entities
   ├── build/               the packaged .mcpack sent to players (generated)
   └── cosmetics.db         player data (SQLite)
   ```

3. Join and run `/cosmetics`. Operators can use every cosmetic by default; other players need an
   unlock (see [Permissions and unlocking](#permissions-and-unlocking)).

The sample pack is packaged and offered to players automatically. Players must accept it to see
balloons; hats, back and offhand cosmetics work without it.

## Commands

| Command | Description | Permission |
| --- | --- | --- |
| `/cosmetics` | Open the cosmetics menu | `nkcosmetics.command.cosmetics` (everyone) |
| `/cosmetics equip <id>` | Equip an unlocked cosmetic | same |
| `/cosmetics unequip <category>` | Unequip one category | same |
| `/cosmetics equipped` | List what you are wearing | same |
| `/cosmetics clear` | Unequip everything | same |
| `/cosmetics use` | Activate your equipped gadget | same |
| `/cosmetics help` | Show player commands | same |
| `/nkcosmetics list [category]` | List cosmetic ids | `nkcosmetics.admin.list` |
| `/nkcosmetics give <player> <id>` | Permanently unlock a cosmetic for a player | `nkcosmetics.admin.give` |
| `/nkcosmetics remove <player> <id>` | Take back a granted unlock | `nkcosmetics.admin.remove` |
| `/nkcosmetics clear <player>` | Unequip everything (works for offline players) | `nkcosmetics.admin.clear` |
| `/nkcosmetics reload` | Reload `config.yml`, the `cosmetics/` files and the resource pack | `nkcosmetics.admin.reload` |
| `/nkcosmetics debug <player>` | Write a player's original and composed skin to `plugin_data/NKCosmetics/debug/` | `nkcosmetics.admin.debug` |

Category names are `hat`, `back`, `offhand`, `balloon`, `pet`, `particle` and `gadget`.
`/nkcosmetics` (alias `/nkc`) needs `nkcosmetics.command.admin` plus the subcommand's permission.
All admin permissions default to operators. `give`, `remove` and `clear` also work for players who
are offline but have joined before.

The menu shows each category with what is equipped and how many cosmetics are unlocked, then a
paginated list showing name, rarity, and whether each cosmetic is equipped, unlocked or locked
(with the permission that unlocks it). A settings page lets players hide their own cosmetics or
stop seeing other players' cosmetics.

## Permissions and unlocking

A player can use a cosmetic if **any** of these is true:

1. it has `unlock: default: true` (everyone owns it);
2. the player has its permission (default `nkcosmetics.<category>.<id>`, e.g.
   `nkcosmetics.hat.royal_crown`), unless the cosmetic sets `unlock: permission: false`;
3. it was granted with `/nkcosmetics give` or the API (stored in the database).

`nkcosmetics.all` grants every cosmetic and is given to operators. Give per-cosmetic permissions
with your permission plugin, or grant unlocks with `/nkcosmetics give` from rank, crate or reward
plugins. If a player loses access (permission removed, unlock revoked, cosmetic disabled or
deleted) the cosmetic is unequipped automatically.

## Configuration

### `config.yml`: global settings

| Key | Default | Meaning |
| --- | --- | --- |
| `storage.sqlite-file` | `cosmetics.db` | Database file in the plugin data folder |
| `storage.autosave-seconds` | `60` | How often changed player data is saved (also saved on quit and shutdown) |
| `resource-pack.source` | `resource-pack` | Folder with the editable pack, relative to the data folder |
| `resource-pack.auto-register` | `true` | Package the folder as an .mcpack on startup and offer it to players |
| `resource-pack.force` | `false` | Require players to accept resource packs to join |
| `skins.preserve-persona` | `true` | Keep Character Creator (persona) skins instead of the server's random colour |
| `skins.accept-large-classic-skins` | `true` | Accept classic skins larger than 128x128 |
| `skins.composed-skin-type` | `classic` | How skins with cosmetics are sent; keep `classic` (see [limitations](#compatibility-and-known-limitations)) |
| `skins.max-texture-size` | `512` | Largest skin texture NKCosmetics may create when a skin has no free space |
| `skins.update-delay-ticks` | `2` | Ticks to batch changes before rebuilding a skin |
| `disabled-worlds` | `[]` | Worlds where all cosmetics are hidden and gadgets cannot be used |
| `menu.page-size` | `8` | Cosmetics per menu page |

### `cosmetics/`: one file per type

Every file has a `settings:` section for that type, followed by its entries.

| File | `settings:` keys | Entries |
| --- | --- | --- |
| `hats.yml` | `enabled`, `replace-slot`, `hide-with-helmet` | `cosmetics:` your hats |
| `back.yml` | `enabled`, `replace-slot`, `hide-with-elytra` | `cosmetics:` your back items |
| `offhand.yml` | `enabled`, `replace-slot`, `hide-with-offhand-item` | `cosmetics:` your offhand items |
| `balloons.yml` | `enabled`, `view-distance`, `update-interval-ticks` | `cosmetics:` your balloons |
| `pets.yml` | `enabled`, `view-distance`, `update-interval-ticks` | `pets:` the built-in pets |
| `particles.yml` | `enabled`, `view-distance`, `frequency`, `max-effects-per-tick` | `particles:` the built-in effects |
| `gadgets.yml` | `enabled`, `hotbar-slot` | `gadgets:` the built-in gadgets |

- `enabled: false` hides the whole category from the menu and unequips it.
- `replace-slot` removes what a persona skin already wears in that slot (hair, headwear and face
  accessories for hats; the persona back item for back cosmetics) before adding yours.
- `hide-with-helmet`, `hide-with-elytra` and `hide-with-offhand-item` hide the cosmetic while the
  player wears a real helmet or elytra, or holds a real item in the offhand.

Each file documents its options in comments. Invalid values fall back to defaults and are logged
with the file and key they came from. Run `/nkcosmetics reload` after editing.

## Adding your own cosmetics

### 1. Make the model

Model the cosmetic in [Blockbench](https://www.blockbench.net/) as a **Bedrock Entity** and export
the geometry (`.geo.json`) and texture (`.png`).

- **Hats, back and offhand items are drawn in player model space**, in pixels: feet at y=0, body
  y=12–24, head y=24–32 (x and z from -4 to 4), back of the body at z=2, left arm at x=4–8. Import
  the vanilla player model as a reference and build around it.
- Bones you add as a "skeleton" from the reference model (for example an empty `head` bone) are
  merged onto the attachment point, so it is fine to keep them.
- Hat, back and offhand textures must be PNG, at most 128x128, and match the geometry's
  `texture_width` and `texture_height` (or an exact multiple). Skin geometry cannot be animated.
- Give everything unique identifiers, e.g. `geometry.nk.royal_crown`.

### 2. Add the files to the resource pack

Put the files in `plugin_data/NKCosmetics/resource-pack/`:

```text
resource-pack/
├── manifest.json
├── models/entity/nkcosmetics/hats/royal_crown.geo.json
├── textures/nkcosmetics/hats/royal_crown.png
├── entity/nkcosmetics/red_balloon.entity.json        (balloons only)
└── animations/nkcosmetics/balloon.animation.json     (balloons only)
```

### 3. Add the YAML entry

In the file for that type, e.g. `cosmetics/hats.yml`:

```yaml
cosmetics:
  royal_crown:
    name: "Royal Crown"
    description: "A golden crown set with a ruby and a sapphire."
    geometry: "geometry.nk.royal_crown"
    texture: "textures/nkcosmetics/hats/royal_crown"   # no .png
    permission: "nkcosmetics.hat.royal_crown"          # optional, this is the default
    rarity: legendary                                  # common, uncommon, rare, epic, legendary, mythic
    enabled: true
    # Optional:
    # unlock:
    #   default: true          everyone owns it
    #   permission: false      the permission does not unlock it
    # offset: [0, 1, 0]        move the model, in pixels
    # replace-slot: false      keep the persona piece in this slot
    # strip-bones: [hat]       also hide these skin bones
    # hide-with-helmet: false  override the file's setting
```

Ids are 1–48 lowercase letters, digits or underscores, unique across all files. The type comes
from the file, so no `type:` key is needed.

### 4. Reload

Run `/nkcosmetics reload`. Every entry is validated, including that its geometry and texture exist
in the pack. Problems are reported per cosmetic and that cosmetic is skipped; the rest still load.

- **Hats, back and offhand items** are sent inside player skins, so new and changed ones work
  immediately, without a restart and even for players who declined the pack.
- **Balloons** are drawn by the client from the resource pack. New balloons need a **server
  restart**, after which players must rejoin to download the updated pack.

### Balloons

A balloon also needs a client entity file in `resource-pack/entity/`, whose identifier matches the
balloon's `entity` setting (default `nkcosmetics:<id>`):

```json
{
	"format_version": "1.10.0",
	"minecraft:client_entity": {
		"description": {
			"identifier": "nkcosmetics:red_balloon",
			"materials": {"default": "entity_alphatest"},
			"textures": {"default": "textures/nkcosmetics/balloons/red_balloon"},
			"geometry": {"default": "geometry.nk.red_balloon"},
			"animations": {"float": "animation.nk.balloon.float"},
			"scripts": {"animate": ["float"]},
			"render_controllers": ["controller.render.default"]
		}
	}
}
```

Balloon models are in entity space (y=0 is the balloon's anchor point). Balloon options in
`balloons.yml`:

```yaml
cosmetics:
  red_balloon:
    name: "Red Balloon"
    geometry: "geometry.nk.red_balloon"
    texture: "textures/nkcosmetics/balloons/red_balloon"
    animation: "animation.nk.balloon.float"   # checked against the pack
    entity: "nkcosmetics:red_balloon"         # client entity identifier
    height: 2.4                               # blocks above the player's feet
    distance: 1.2                             # how far it trails behind
    offset: [0.6, 0, 0]                       # right, up, forward (blocks)
    scale: 1.0
    tether: true                              # lead from the player to the balloon
```

## The resource pack

With `resource-pack.auto-register: true` (the default), NKCosmetics packages
`plugin_data/NKCosmetics/resource-pack/` into `build/NKCosmetics.mcpack` on startup and adds it to
the server's pack stack. You do not need to copy it into the server's `resource_packs/` folder.
Whenever the folder's content changes, the pack's patch version is bumped automatically so
players download the new version instead of keeping a cached copy.

To distribute the pack yourself instead (for example to host it elsewhere), set `auto-register:
false`, zip the `resource-pack/` folder (with `manifest.json` at the root of the zip), put it in
the server's `resource_packs/` folder and list it in `resource_packs.yml`. Remember to bump the
version in `manifest.json` whenever you change it.

Only balloons need players to have the pack. Set `resource-pack.force: true` to make accepting it
mandatory.

## Built-in pets, particles and gadgets

Configure these in `cosmetics/pets.yml`, `particles.yml` and `gadgets.yml`. Each entry accepts
`enabled`, `name`, `permission` and `default-unlocked`; gadgets also accept `cooldown-seconds`
(1–300). Ids that are not listed below are reported and ignored.

| Pets | Particles | Gadgets (default cooldown) |
| --- | --- | --- |
| `mini_wolf`, `mini_cat`, `mini_fox`, `mini_panda`, `mini_pig`, `mini_chicken`, `mini_rabbit`, `mini_axolotl`, `mini_bee`, `mini_parrot` | `heart_aura`, `emerald_halo`, `enchant_spiral`, `flame_trail`, `rainbow_trail`, `snow_trail` | `confetti_cannon` (5s), `bubble_wand` (3s), `starburst` (5s), `flower_shower` (4s) |

**Pets** follow their owner over terrain (bees and parrots fly), and sit or wander nearby when the
owner stands still. They cannot be hit, pushed or interacted with, and are never saved with the
world.

**Particles** are only sent to players within `view-distance`, with a global per-tick budget
(`max-effects-per-tick`) so busy servers stay smooth.

<a id="gadgets"></a>
**Gadgets** place a tool in the configured `hotbar-slot` (default 8, the ninth slot) only if that
slot is empty; your items are never replaced. Use `/cosmetics use` if the slot is occupied. The
tool cannot be dropped or moved and is removed on unequip, death and disconnect. Effects are
visual only: no damage, movement or block changes. Gadgets cannot be used in `disabled-worlds`.

## How skin cosmetics work

Hats, back and offhand items are added to the player's own skin geometry, so the client animates
them with the head, body or arm exactly like the player model.

- Bedrock's Character Creator (persona) skins have dedicated attachment bones: `helmet` (where
  hair and headwear are baked), `bodyarmor` (persona back items) and `leftitem` (the offhand). A
  cosmetic is attached to the matching bone and, with `replace-slot`, replaces the persona piece
  there. Classic skins without those bones fall back to `head`, `body` and `leftarm`.
- The cosmetic's texture is packed into unused space of the player's skin texture. If there is no
  room, the texture is enlarged (up to `max-texture-size`).
- PocketMine normally replaces persona skins with a random solid colour. NKCosmetics keeps the
  original skin data (`preserve-persona`), so persona players look like themselves, with or
  without cosmetics.
- The player's original skin is kept and restored when cosmetics are removed. If a player changes
  skin in game, cosmetics are re-applied to the new skin.
- Players who turn off "other players' cosmetics" receive everyone else's original skin.

## Developer API

Other plugins use `ninjaknights\cosmetics\api\NKCosmeticsAPI`. Add `NKCosmetics` to `depend` or
`softdepend` in your `plugin.yml`.

```php
use ninjaknights\cosmetics\api\NKCosmeticsAPI;
use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\manager\EquipResult;

$api = NKCosmeticsAPI::get();   // null if NKCosmetics is not enabled
if($api !== null){
	// Reward: permanently unlock a cosmetic (works for offline players who joined before)
	$api->unlock("Steve", "royal_crown");

	// Equip it for an online player
	if($api->equip($player, "royal_crown") !== EquipResult::SUCCESS){
		// LOCKED, DISABLED, WORLD_DISABLED, CANCELLED, ...
	}

	// Register a cosmetic from your plugin (its assets must be in the cosmetics resource pack)
	$api->registerCosmetic("event_cap", [
		"type" => "hat",
		"name" => "Event Cap",
		"geometry" => "geometry.myplugin.event_cap",
		"texture" => "textures/myplugin/event_cap",
	]);
}
```

| Method | Description |
| --- | --- |
| `get()` | The API instance, or `null` when NKCosmetics is disabled |
| `registerCosmetic(string $id, array $config)` | Add a hat, back, offhand or balloon cosmetic, validated like a YAML entry (plus a `type` key). Throws `InvalidArgumentException` listing all problems. Pets, particles and gadgets are rejected. |
| `unregisterCosmetic(string $id)` | Remove an API or YAML cosmetic; wearers lose it |
| `getCosmetic(string $id)` / `getCosmetics(?CosmeticCategory)` | Look up definitions |
| `isUnlocked(Player, string $id)` | Whether the player can use it |
| `unlock(string $player, string $id)` / `revoke(...)` | Grant or take back a stored unlock |
| `equip(Player, string $id)` | Returns an `EquipResult` |
| `unequip(Player, CosmeticCategory)` / `getEquipped(Player, CosmeticCategory)` | Manage one slot |
| `getState(Player)` | `PlayerCosmeticState`: equipped ids, granted unlocks and visibility settings |

API cosmetics survive `/nkcosmetics reload`. Register them in your plugin's `onEnable()`; a balloon
must be registered before players join for clients to know its entity type.

### Events

All events are in `ninjaknights\cosmetics\event` and are cancellable. Each carries a `ChangeCause`
(`PLAYER`, `ADMIN`, `API`, `RESTORE`, `ACCESS_LOST`, `WORLD`).

| Event | When | Cancelling |
| --- | --- | --- |
| `CosmeticEquipEvent` | Before a cosmetic is equipped (after access checks). `getReplaced()` is the cosmetic it replaces in that slot. | Keeps the current cosmetic |
| `CosmeticUnequipEvent` | Before a cosmetic is unequipped | Keeps it equipped, **except** for forced causes (`ACCESS_LOST`, `WORLD`), which always happen |
| `CosmeticUnlockEvent` | Before an unlock is granted (player may be offline; see `getOnlinePlayer()`) | Prevents the grant |
| `CosmeticRevokeEvent` | Before a granted unlock is removed | Keeps the unlock |

## Compatibility and known limitations

- Tested in game on **Axolotl-PM 5.51.0 with Bedrock 1.26.60**, using one client. Stock
  PocketMine-MP 5 has not been tested in a live session, and seeing other players' cosmetics has
  not yet been verified with several clients at once.
- **Skins with cosmetics must be sent as classic skins** (`skins.composed-skin-type: classic`).
  When the persona flag is kept, the client rebuilds the skin from its persona pieces and the
  cosmetics disappear.
- Skin cosmetics cannot be animated, and the combined skin texture is limited to
  `max-texture-size`. A cosmetic that does not fit is skipped and the player is told why.
- Balloons are invisible to players who decline the resource pack, and new balloon types need a
  restart plus a rejoin.
- Offhand cosmetics are visual only and hidden while a real offhand item is held (configurable),
  so they never overlap held items.
- Pets are baby vanilla mobs rendered by the client; their exact look follows the client's
  vanilla resources.

## Development

```sh
composer install          # dependencies, including Axolotl-PM for static analysis
```

Release PHARs are built and published through BedrockNexus. The build script, tests and
PHPStan/PHPUnit configuration are not part of this repository.

Source layout:

```text
src/ninjaknights/cosmetics/
├── api/            public API for other plugins
├── builtin/        pet, particle and gadget catalogs
├── command/        /cosmetics and /nkcosmetics
├── cosmetic/       definitions, categories and rarities
├── entity/         balloon and pet entities
├── event/          cosmetic lifecycle events
├── geometry/       skin geometry composition and texture packing
├── listener/       player lifecycle, gadgets and skin visibility
├── manager/        equipment, sessions and permissions
├── menu/           /cosmetics forms
├── registry/       cosmetic registry and YAML loading
├── renderer/       one renderer per category
├── resourcepack/   pack asset index and packaging
├── skin/           skin adapter and composed-skin management
└── storage/        storage interface and SQLite backend
resources/          default config.yml, cosmetics/*.yml and fallback skin geometry
resource-pack/      sample resource pack (crown, wings, staff, balloon)
```

## License

NKCosmetics is licensed under the GNU General Public License v3.0 only (`GPL-3.0-only`). The bundled
[pmforms](https://github.com/dktapps-pm-pl/pmforms) library is LGPL-3.0; its license is included in
the PHAR under `licenses/`.
