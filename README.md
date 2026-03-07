# PostVote — phpBB Extension

Reddit/StackOverflow-style post voting for phpBB 3.3+.

## Features

- **Up/down voting** on posts — one vote per user, changeable
- **Vote-based sorting** — highest-scored posts shown first by default
- **User reputation** — automatically calculated from vote totals across all posts
- **Leaderboards** — top users by reputation and top posts by score
- **AJAX voting** — no page reload, with CSRF protection and rate limiting
- **ACP settings** — enable/disable, configure rate limits, cache TTL, downvoting

## Requirements

- phpBB 3.3.0 or newer
- PHP 8.0 or newer

## Installation

1. Download the latest release zip
2. Extract into your forum's `ext/` directory — the result should be `ext/benjibabs/postvote/`
3. Go to **ACP → Customise → Extensions** and enable **PostVote**

## Configuration

After enabling, go to **ACP → Extensions → PostVote Settings** to configure:

| Setting | Default | Description |
|---|---|---|
| Enable PostVote | Yes | Enable/disable globally |
| Allow downvoting | Yes | Let users cast negative votes |
| Votes per period | 10 | Rate limit count |
| Rate-limit period | 60s | Rate limit window |
| Leaderboard cache TTL | 300s | How long to cache leaderboard data |

## Permissions

Two permissions are added under the **Post** category:

- `u_postvote` — Can upvote posts
- `u_postvote_down` — Can downvote posts

## License

[GNU General Public License v2](license.txt)

## Author

Babatope (Ben) Babajide — [itcrackteam.com](https://itcrackteam.com)
