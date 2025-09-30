# MroongaSearch for Omeka S

This module dramatically improves Japanese full-text search capabilities in Omeka S. It operates in two modes, depending on your server environment, ensuring enhanced search functionality is always available.

First and foremost, **this module enables meaningful Japanese full-text search even on a standard Omeka S installation without the Mroonga storage engine.** It intelligently uses the database's built-in features to provide strict `AND`/`OR` search logic, a feature missing in the default Omeka S setup for CJK languages.

If the Mroonga engine is installed and active, the module automatically upgrades the search functionality to use it, providing even faster and more powerful full-text search.

## Module History

- **Original:** [Kentaro Fukuchi](https://github.com/fukuchi/Omeka-S-module-mroonga-search)
- **Modified:** [Kazufumi Fukuda](https://github.com/fukudakz/Omeka-S-module-mroonga-search/)
- **Enhanced:** [Toshihito Waki](https://github.com/wakitosh/MroongaSearch)

This version has been significantly enhanced to provide robust fallback mechanisms, safer installation/uninstallation, and improved search logic.

## Key Features

1.  **Enhanced Japanese Full-Text Search (without Mroonga):**
    - Omeka S's default full-text search does not work well for Japanese. This module provides an alternative that enables a pseudo-full-text search by using `LIKE` for CJK (Chinese, Japanese, Korean) languages.

2.  **Mroonga-Powered Search (with Mroonga):**
    - If the Mroonga plugin is active in your database, the module will automatically alter the `fulltext_search` table to use the Mroonga engine.
    - This provides high-speed, CJK-aware full-text search.

3.  **MeCab Tokenizer Support (Optional):**
    - For even more accurate Japanese searches, the module supports `groonga-tokenizer-mecab`.
    - Using MeCab for morphological analysis allows the search to correctly understand word boundaries in Japanese text.
    - If the MeCab tokenizer is not available, the module automatically uses Mroonga's standard tokenizer.

4.  **Strict `AND` / `OR` Logic:**
    - Unlike Omeka S's default natural language mode, this module enforces strict search conditions.
    - `AND`: Returns only results that contain all specified keywords.
    - `OR`: Returns results that contain any of the specified keywords.

5.  **Safe Installation and Uninstallation:**
    - **On Install:** The module checks if Mroonga is active. It then safely alters the table engine. If any issues occur, it attempts to recover gracefully.
    - **On Uninstall:** The module reverts the `fulltext_search` table back to the standard InnoDB engine, ensuring your Omeka S site remains fully functional without the module.

## Installation

While this module works without Mroonga, installing it is recommended for better performance.

1.  **Install Mroonga (Recommended):**
    - For high-speed, CJK-aware full-text search, install the Mroonga storage engine in your database.
    - See the official Mroonga website for instructions: [https://mroonga.org/](https://mroonga.org/)

2.  **Install MeCab Tokenizer (Optional but Recommended):**
    - For even more accurate Japanese morphological analysis, installing `groonga-tokenizer-mecab` is recommended.

3.  **Install the Module:**
    - Unzip the module and place the `MroongaSearch` folder into your Omeka S `modules` directory.
    - Log in to your Omeka S admin dashboard, navigate to the "Modules" section, and activate "MroongaSearch".

**Note:** Activating the module may trigger a search index rebuild job, which can take some time depending on the amount of data.

No further configuration is required. The module will automatically detect your environment and apply the appropriate search enhancements.

## Usage

When performing a full-text search, you can control the search logic by adding a `logic` parameter to the query URL:

-   **AND Search:** `?fulltext_search=keyword1+keyword2&logic=and`
-   **OR Search:** `?fulltext_search=keyword1+keyword2&logic=or`

If the `logic` parameter is omitted, it typically defaults to `AND`.

## License

This module is licensed under the MIT License.

---

# MroongaSearch for Omeka S (日本語)

このモジュールは、Omeka Sの日本語全文検索機能を改善します。サーバー環境に応じて2つのモードで動作し、検索機能を強化します。

主な特長として、**Mroongaストレージエンジンがインストールされていない標準的なOmeka S環境でも、実用的な日本語全文検索を可能にします**。データベースの標準機能を用いることで、Omeka Sのデフォルトでは実現が難しい厳密な`AND`/`OR`検索を提供します。

Mroongaエンジンが利用可能な環境では、モジュールは自動的にMroongaを利用し、さらに高速な全文検索を実現します。

## モジュールの来歴

-   **オリジナル版:** [Kentaro Fukuchi](https://github.com/fukuchi/Omeka-S-module-mroonga-search)
-   **改訂版:** [Kazufumi Fukuda](https://github.com/fukudakz/Omeka-S-module-mroonga-search/)
-   **機能強化版:** [Toshihito Waki](https://github.com/wakitosh/MroongaSearch)

このバージョンは、フォールバック機能の強化、安全なインストール／アンインストール処理、検索ロジックの改善など、大幅な機能強化が施されています。

## 主な機能

1.  **日本語全文検索の強化 (Mroongaなし環境):**
    -   Omeka Sのデフォルト全文検索は日本語でうまく機能しません。このモジュールは、CJK（日中韓）言語に`LIKE`検索を利用することで擬似的に全文検索が利用できるようにします。

2.  **Mroongaによる高速検索 (Mroongaあり環境):**
    -   データベースでMroongaプラグインが有効な場合、モジュールは自動的に`fulltext_search`テーブルのエンジンをMroongaに切り替えます。
    -   これにより、CJK言語に対応した高速な全文検索が実現します。

3.  **MeCab連携による高精度な検索（オプション）:**
    -   `groonga-tokenizer-mecab`が利用可能な環境では、MeCabをトークナイザとして利用し、より高精度な日本語検索を実現します。
    -   形態素解析によって日本語の単語の区切りを正確に認識できるようになります。
    -   MeCabが利用できない場合、モジュールは自動的にMroonga標準のトークナイザを使用します。

4.  **厳密な `AND` / `OR` 検索:**
    -   Omeka Sのデフォルトである自然言語検索とは異なり、このモジュールは厳密な検索条件を適用します。
    -   `AND`検索: 指定したすべてのキーワードを含む資料のみを返します。
    -   `OR`検索: 指定したキーワードのいずれかを含む資料を返します。

5.  **安全なインストールとアンインストール:**
    -   **インストール時:** Mroongaが有効かを確認し、安全にテーブルエンジンを変更します。問題が発生した場合は、正常な状態を維持しようと試みます。
    -   **アンインストール時:** `fulltext_search`テーブルを標準のInnoDBエンジンに戻します。これにより、モジュールを無効にしてもサイトが問題なく機能し続けます。

## インストール

このモジュールはMroongaなしでも動作しますが、より高いパフォーマンスを得るためにMroongaのインストールを推奨します。

1.  **Mroongaのインストール（推奨）:**
    -   CJK言語対応の高速な全文検索を利用するために、データベースにMroongaストレージエンジンをインストールしてください。
    -   導入手順については公式サイトを参照してください: [https://mroonga.org/](https://mroonga.org/)

2.  **MeCabトークナイザのインストール（オプション、推奨）:**
    -   より高精度な日本語の形態素解析のため、`groonga-tokenizer-mecab`のインストールを推奨します。

3.  **本モジュールのインストール:**
    -   モジュールを解凍し、`MroongaSearch`フォルダをOmeka Sの`modules`ディレクトリに配置します。
    -   Omeka Sの管理画面にログインし、「モジュール」セクションで「MroongaSearch」を有効化します。

**注意:** モジュールを有効化すると、検索インデックスの再構築ジョブが実行される場合があります。データ量によっては完了まで時間がかかることがあります。

これ以上の設定は不要です。モジュールが自動的に環境を検出し、適切な検索強化を適用します。

## 利用方法

全文検索の際、URLに`logic`パラメータを追加することで、検索ロジックを制御できます。

-   **AND検索:** `?fulltext_search=キーワード1+キーワード2&logic=and`
-   **OR検索:** `?fulltext_search=キーワード1+キーワード2&logic=or`

`logic`パラメータを省略した場合、通常は`AND`検索として扱われます。

## ライセンス

このモジュールはMITライセンスの下で公開されています。


