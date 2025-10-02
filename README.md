# MroongaSearch for Omeka S

This module for Omeka S enhances the default full-text search by leveraging the [Mroonga](https://mroonga.org/) storage engine for MySQL/MariaDB. It provides strict `AND`/`OR` search semantics, better performance for large datasets, and CJK (Chinese, Japanese, Korean) language support.

### Provenance

- **Original:** [Kentaro Fukuchi](https://github.com/fukuchi/Omeka-S-module-mroonga-search)
- **Modified:** [Kazufumi Fukuda](https://github.com/fukudakz/Omeka-S-module-mroonga-search/)
- **Enhanced:** [Toshihito Waki](https://github.com/wakitosh/MroongaSearch)

This version has been significantly enhanced to provide robust fallback mechanisms, safer installation/uninstallation, and improved search logic.

## Key Features

- **Strict AND/OR Search**: Enforces true `AND` or `OR` logic for multi-term queries, unlike Omeka S's default natural language mode.
- **CJK Support**: Provides robust search for Japanese and other CJK languages when Mroonga and its `TokenMecab` tokenizer are available. A fallback `LIKE` search is used for single CJK terms in non-Mroonga environments.
- **Diagnostics Page**: A central admin page to monitor Mroonga status, manage the search engine, view indexed data, and run re-indexing jobs.
- **Manual Engine Switching**: Safely switch the `fulltext_search` table's engine between Mroonga and InnoDB.
- **Segmented Re-indexing**: Manually run re-indexing jobs for different resource types (Items, Item Sets, Media) to minimize downtime.

## Installation

### Prerequisites

- **Mroonga**: The Mroonga plugin must be installed and `ACTIVE` in your MySQL/MariaDB server.
- **TokenMecab (Recommended)**: For optimal Japanese language tokenization, `TokenMecab` should be available to Mroonga.

### Steps

1.  Install the module like any other Omeka S module.
2.  Upon installation, the module automatically checks for an `ACTIVE` Mroonga plugin.
    - If **active**, it switches the `fulltext_search` table engine to `Mroonga`. It will also set `tokenizer "TokenMecab"` if available.
    - If **not active**, it remains on `InnoDB` and operates in a fallback mode.
3.  Navigate to the admin **Mroonga Search** page (`/admin/mroonga-search/diagnostics`) to verify the status and manually trigger re-indexing if needed.

## Usage

### Advanced Search

In the advanced search form, the module enhances the "Full-text search" field:

- **Multi-term queries**: Use the logic dropdown (AND/OR) to perform strict searches. For example, searching for "cat dog" with `AND` will only return results containing both "cat" and "dog".
- **Phrase search**: Enclose terms in double quotes (e.g., `"ancient history"`) to search for the exact phrase.

### Diagnostics Page

The **Mroonga Search** admin page is your hub for managing the search system.

- **Overview**: Shows Mroonga plugin status, the current engine of the `fulltext_search` table, and whether `TokenMecab` is in use. "Effective Mroonga" status indicates if both the plugin and engine are correctly configured.
- **FULLTEXT Indexes**: Lists all `FULLTEXT` indexes on the `fulltext_search` table.
- **Resource Counts**: Displays the number of indexed resources (`items`, `item_sets`, `media`) and compares them against the actual totals in the database.
- **Recent Jobs**: Shows the status of recent indexing-related jobs. You can open the job details page or view the job log directly.
- **Indexing**:
    - **Engine Switching**: Manually switch the table engine to `Mroonga` or back to `InnoDB`. Use this for testing or maintenance.
    - **Manual Re-index**: Run segmented re-indexing jobs. This is the recommended way to rebuild the search index as it minimizes search disruption. A dynamic confirmation will warn you if you are about to re-index a large number of resources.

## For Developers: Technical Details

### Search Behavior

The module's behavior depends on whether Mroonga is "effectively" active (Mroonga plugin is `ACTIVE` and `fulltext_search` table engine is `Mroonga`).

- **Effective Mroonga: ON**
    - **Multi-term queries**: Uses Mroonga's `MATCH(...) AGAINST(...)` for strict `AND`/`OR` logic. Queries are tokenized by Mroonga (e.g., via `TokenMecab`).
    - **Single-term CJK queries**: Handled by Mroonga's tokenizer.
- **Effective Mroonga: OFF** (Fallback Mode)
    - **Multi-term queries**: Uses standard InnoDB `MATCH(...) AGAINST(... IN BOOLEAN MODE)` to simulate `AND`/`OR`.
    - **Single-term CJK queries**: Uses `LIKE '%term%'` for a substring match, providing better recall for un-tokenized CJK text.

### Automatic Engine Management

The module includes a self-healing mechanism in `Module.php` that runs on every page load:
- If the Mroonga plugin is `ACTIVE` but the table engine is `InnoDB`, it automatically switches the table to `Mroonga`.
- If the plugin is `INACTIVE` but the table engine is `Mroonga`, it automatically reverts the table to `InnoDB` to prevent errors.

This ensures the system remains functional even if the database environment changes, but for explicit control, use the Diagnostics page.

## License

This module is released under the MIT License. See the LICENSE file for details.

---

# MroongaSearch for Omeka S (日本語)

このモジュールは、MySQL/MariaDB のストレージエンジン [Mroonga](https://mroonga.org/) を活用して、Omeka S のデフォルト全文検索を強化します。厳密な `AND`/`OR` 検索、大規模データセットでのパフォーマンス向上、CJK（日中韓）言語サポートを提供します。

### モジュールの来歴

-   **オリジナル版:** [Kentaro Fukuchi](https://github.com/fukuchi/Omeka-S-module-mroonga-search)
-   **改訂版:** [Kazufumi Fukuda](https://github.com/fukudakz/Omeka-S-module-mroonga-search/)
-   **機能強化版:** [Toshihito Waki](https://github.com/wakitosh/MroongaSearch)

このバージョンは、フォールバック機能の強化、安全なインストール／アンインストール処理、検索ロジックの改善など、大幅な機能強化が施されています。

## 主な機能

- **厳密なAND/OR検索**: 複数キーワード検索時に、Omeka S デフォルトの自然言語モードとは異なり、忠実な `AND` または `OR` 条件を適用します。
- **CJKサポート**: Mroonga と `TokenMecab` トークナイザが利用可能な場合、日本語を含むCJK言語で安定した検索を実現します。非Mroonga環境では、単一のCJK単語に対して `LIKE` を使ったフォールバック検索が行われます。
- **Diagnostics（診断）ページ**: Mroonga の状態監視、検索エンジンの管理、インデックス化されたデータの確認、再インデックスジョブの実行を中央管理する管理者ページ。
- **手動エンジン切替**: `fulltext_search` テーブルのエンジンを Mroonga と InnoDB の間で安全に切り替えます。
- **分割再インデックス**: リソース種別（アイテム、アイテムセット、メディア）ごとに再インデックスジョブを手動で実行し、ダウンタイムを最小限に抑えます。

## インストール

### 前提条件

- **Mroonga**: MySQL/MariaDB サーバーに Mroonga プラグインがインストールされ、`ACTIVE` になっている必要があります。
- **TokenMecab（推奨）**: 最適な日本語の形態素解析のために、Mroonga が `TokenMecab` を利用できる状態が推奨されます。

### 手順

1.  他の Omeka S モジュールと同様にインストールします。
2.  インストール時、モジュールは Mroonga プラグインが `ACTIVE` かどうかを自動で確認します。
    - **ACTIVE の場合**: `fulltext_search` テーブルのエンジンを `Mroonga` に切り替えます。`TokenMecab` が利用可能であれば、それも設定します。
    - **非ACTIVE の場合**: `InnoDB` のまま、フォールバックモードで動作します。
3.  管理者画面の **Mroonga Search** ページ（`/admin/mroonga-search/diagnostics`）にアクセスし、状態を確認し、必要であれば手動で再インデックスを実行してください。

## 使い方

### 詳細検索

詳細検索フォームにおいて、このモジュールは「全文検索」フィールドを強化します。

- **複数キーワード検索**: 論理演算ドロップダウン（AND/OR）を使い、厳密な検索を実行します。例えば "猫 犬" を `AND` で検索すると、「猫」と「犬」の両方を含む結果のみが返されます。
- **フレーズ検索**: ダブルクォートで語句を囲む（例: `"古代の歴史"`）と、そのフレーズに完全に一致するものを検索します。

### Diagnostics（診断）ページ

**Mroonga Search** 管理ページは、検索システムを管理するためのハブです。

- **Overview**: Mroonga プラグインの状態、`fulltext_search` テーブルの現在のエンジン、`TokenMecab` が使用中かを表示します。「Effective Mroonga」のステータスは、プラグインとエンジンが両方とも正しく設定されているかを示します。
- **FULLTEXT Indexes**: `fulltext_search` テーブルに存在するすべての `FULLTEXT` インデックスを一覧表示します。
- **Resource Counts**: インデックス化されたリソース（`items`, `item_sets`, `media`）の数を表示し、データベース内の実際の総数と比較します。
- **Recent Jobs**: インデックス関連の最近のジョブの状態を表示します。ジョブ詳細ページを開いたり、ジョブログを直接表示したりできます。
- **Indexing**:
    - **Engine Switching**: テーブルエンジンを `Mroonga` に切り替えたり、`InnoDB` に戻したりできます。テストやメンテナンスに使用します。
    - **Manual Re-index**: 分割された再インデックスジョブを実行します。検索の中断を最小限に抑えるため、これが推奨されるインデックス再構築方法です。大量のリソースを再インデックスしようとすると、動的な確認ダイアログが警告を表示します。

## 開発者向け: 技術詳細

### 検索の挙動

このモジュールの挙動は、Mroonga が「有効（effective）」であるかどうか（Mroonga プラグインが `ACTIVE` かつ `fulltext_search` テーブルのエンジンが `Mroonga`）に依存します。

- **Mroonga有効時**
    - **複数キーワード検索**: Mroonga の `MATCH(...) AGAINST(...)` を使用し、厳密な `AND`/`OR` ロジックを適用します。クエリは Mroonga によって（例: `TokenMecab` で）トークン化されます。
    - **単一CJK単語の検索**: Mroonga のトークナイザによって処理されます。
- **Mroonga無効時**（フォールバックモード）
    - **複数キーワード検索**: 標準の InnoDB の `MATCH(...) AGAINST(... IN BOOLEAN MODE)` を使用して `AND`/`OR` をシミュレートします。
    - **単一CJK単語の検索**: `LIKE '%term%'` を使用した部分一致検索を行い、トークン化されていないCJKテキストに対する再現率（recall）を高めます。

### エンジンの自動管理

このモジュールは、ページ読み込みごとに実行される自己修復メカニズムを `Module.php` に含んでいます。
- Mroonga プラグインが `ACTIVE` なのにテーブルエンジンが `InnoDB` の場合、自動的にテーブルを `Mroonga` に切り替えます。
- プラグインが `INACTIVE` なのにテーブルエンジンが `Mroonga` の場合、エラーを防ぐために自動的にテーブルを `InnoDB` に戻します。

これにより、データベース環境が変更されてもシステムは機能し続けますが、明示的な制御を行いたい場合は Diagnostics ページを使用してください。

## ライセンス

このモジュールは MIT ライセンスで提供されています。詳細は LICENSE ファイルをご覧ください。

