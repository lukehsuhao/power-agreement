# 實作計劃：Power Agreement WooCommerce 結帳合約外掛

## 概述

依據 `specs/2026-05-04-power-agreement-design.md`，分 6 個階段實作 WooCommerce 結帳合約外掛。每階段可獨立合併並產生可驗證的價值，採由內而外（domain → integration → UI）的順序，先把無 UI 的核心邏輯寫好並測試完整，再逐步接 Classic / Block 兩條結帳流。

## 需求重述

在 WooCommerce 結帳「下單」按鈕之前，注入可摺疊合約區塊與「同意」勾選框。後台維護單一全站合約；提交結帳時若未勾選，伺服器端擋下並顯示錯誤；勾選成功則在訂單建立時寫入 4 個 HPOS-safe order meta（合約 HTML 快照、SHA-256、ISO 時間、客戶 IP）作為法律佐證，訂單編輯頁可查閱。Classic 與 Block Checkout 兩種結帳介面皆支援。WP 6.5+ / WC 8.3+ / PHP 8.1+。

## 已知風險（來自研究與經驗）

- **風險：Block Checkout `IntegrationInterface` API 仍標 experimental，欄位名稱於 WC 版本間有變動**
  - 緩解：版本鎖定 WC 8.3+，於 `BlockCheckout::is_active()` 內以 `class_exists` 與 `method_exists` 防衛性檢查；Schema 走官方 `ExtendSchema::register_endpoint_data()` 而非自製 hook
- **風險：`wp_editor()` 在 Settings API `do_settings_sections()` 內初始化 TinyMCE 偶發失效（focus/visual mode 問題）**
  - 緩解：在 admin 頁面的 `admin_enqueue_scripts` 上明確 `wp_enqueue_editor()`，並用 `wp_editor( $content, $editor_id, [ 'textarea_name' => ... ] )` 而非依賴 Settings API 內建 render callback
- **風險：HPOS / 非 HPOS 雙模式測試矩陣**
  - 緩解：`.wp-env.json` 提供兩組 config；CI matrix 跑兩次；`OrderMetaWriter` 統一只用 `WC_Order` API
- **風險：`WC_Geolocation::get_ip_address()` 在 reverse proxy / Cloudflare 後可能取錯**
  - 緩解：WC 此 API 已處理 `HTTP_X_FORWARDED_FOR` / `HTTP_X_REAL_IP` / `HTTP_CLIENT_IP`；超出範圍時 fallback 寫 `'unknown'` 而非阻斷下單
- **風險：`hidden` 屬性元素無法 CSS transition**
  - 緩解：accordion 用 class toggle（`.is-open`）+ `max-height` transition，`hidden` 屬性僅在初始 / 完全收合時加上以避免 a11y readers 朗讀展開內容
- **風險：`wp_kses_post` 對舊版合約 HTML 解讀會隨核心更新而變**
  - 緩解：寫入時與輸出時各做一次 `wp_kses_post`；訂單頁顯示時也仍套，雙保險
- **風險：`woocommerce_review_order_before_submit` 會在 ajax fragment 重新整理時重複觸發，造成多份 accordion DOM**
  - 緩解：HTML 內以固定 `id` 渲染，accordion JS 採 event delegation + 檢查 `data-power-agreement-initialized` 旗標；CSS 不依賴 JS 狀態
- **風險：未發現額外已知風險**

## 架構變更

完全新建專案。最終結構參見 design doc §2.3。

## 資料流分析

### 流程 A — 後台設定儲存

```
Admin 表單 ──▶ Settings API ──▶ sanitize_callback ──▶ wp_options
   │              │                    │                  │
   ▼              ▼                    ▼                  ▼
[未登入?]    [nonce 失敗?]      [內容超長?]         [DB 寫入失敗?]
[無權限?]    [field 缺?]        [HTML 有 script?]   [serialize 失敗?]
```

每階段處理：
- 未登入 / 無權限 → WP 自動跳 403（`manage_woocommerce`）
- nonce 失敗 → Settings API 自動拋錯
- field 缺 → `SettingsRepository::defaults()` 補
- 內容超長 → `mb_substr` 截斷（title 100 / consent_text 200）
- HTML 有 script → `wp_kses_post` 過濾
- DB 寫入失敗 → WP 顯示 admin notice（Settings API 內建）

### 流程 B — Classic Checkout 提交

```
GET /checkout ──▶ render accordion ──▶ POST /?wc-ajax=checkout ──▶ checkout_process ──▶ create_order ──▶ DB
      │                  │                       │                       │                    │             │
      ▼                  ▼                       ▼                       ▼                    ▼             ▼
[功能停用?]         [內容空?]              [nonce 失敗?]           [未勾選?]           [meta 寫入失敗?]  [HPOS 切換?]
[非 checkout?]                              [session 過期?]         [enabled 中途關?]   [IP 取不到?]
```

處理要點：
- 功能停用 / 內容空 → 不注入 HTML，後續流程透明 no-op
- 未勾選 → `wc_add_notice( $msg, 'error' )` 阻斷
- enabled 中途關 → 以 request 開始當下的設定為準（呼叫一次 `SettingsRepository::settings()` 並 cache 在物件屬性）
- IP 取不到 → 寫 `'unknown'`，不阻斷
- meta 寫入失敗（極罕見）→ 紀錄至 `error_log` + `wc_get_logger()->error()`，不擋訂單

### 流程 C — Block Checkout 提交

```
React 載入 ──▶ Cart endpoint ──▶ user toggle ──▶ extensionCartUpdate ──▶ POST /wc/store/checkout ──▶ Hook validate ──▶ Hook update_meta ──▶ DB
    │              │                    │                   │                      │                       │                    │             │
    ▼              ▼                    ▼                   ▼                      ▼                       ▼                    ▼             ▼
[bundle 失敗?]  [Schema 缺?]       [state 不同步?]     [namespace 不符?]    [Cart-Token 失敗?]      [未勾選?]            [meta 失敗?]    [HPOS?]
```

處理要點：
- bundle 失敗 → 後端驗證仍會擋，使用者看到通用 400 error；不破壞下單體驗
- Schema 缺 → `register_endpoint_data` 失敗 → `BlockCheckout::is_active()` 回 false，整個 integration 跳過
- 未勾選 → `RouteException( 'power_agreement_required', $msg, 400 )` → JSON `{code, message}`，前端 React 顯示在 checkbox 旁
- meta 失敗 → 同流程 B：log 但不擋訂單

### 流程 D — 訂單後台顯示

```
GET /admin/order/:id ──▶ 讀 order meta ──▶ 渲染 metabox
       │                       │                  │
       ▼                       ▼                  ▼
[權限不足?]              [meta 缺?]          [HTML 過時?]
                         [hash 對不上?]
```

處理要點：
- meta 缺（舊訂單在外掛安裝前下單）→ `AgreementSnapshot::readFrom()` 回 `null`，metabox 顯示「無同意紀錄」
- hash 對不上（不太可能）→ 顯示警示符號但不擋頁面

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
| --------- | ------------ | -------- | -------- | ----------- |
| `Requirements::satisfied()` | PHP/WP/WC 版本太舊 | 環境錯誤 | admin notice + early return | 是（admin） |
| `SettingsRepository::save()` | DB 寫入失敗 | 持久化錯誤 | Settings API 自動 notice | 是（admin） |
| `SettingsRepository::content()` | 內容空 | 業務狀態 | 前端略過注入；後台顯示 warning | 是 |
| `ConsentValidator::validate()` | 未勾選 | 驗證錯誤 | `wc_add_notice` / `RouteException` | 是 |
| `AgreementSnapshot::captureNow()` | IP 取不到 | 缺資料 | 寫 `'unknown'` 不阻斷 | 否（後台可見） |
| `OrderMetaWriter::write()` | `update_meta_data` 例外 | 持久化錯誤 | log + 繼續，不擋訂單 | 否（log only） |
| `OrderAdminDisplay::render()` | meta 缺 | 缺資料 | 顯示「無同意紀錄」 | 是（admin） |
| `BlockCheckout::register_*` | Block API 不存在 | 環境錯誤 | `is_active()` 回 false | 否 |

> 已檢查無「處理方式=無 + 使用者可見=靜默」的 critical gap。

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
| ---------- | -------- | ------- | ------- | ----------- | -------- |
| Classic 重複觸發 fragment | DOM 出現多份 accordion | 是（id + flag） | E2E | 是 | 自動 |
| Block JS bundle 載入失敗 | accordion 缺 | 是（後端擋） | 整合 | 否（不影響功能） | 重整頁面 |
| 啟用後合約內容仍為空 | 結帳頁無內容可看 | 是（後台 warning） | 整合 | 是（admin） | 後台補齊 |
| 訂單建立中途切換 HPOS | meta 寫不到正確表 | 部分（仰賴 WC API） | 整合 matrix | 否 | 切換後重啟 WC |
| 同一筆訂單兩次寫 meta | 後寫覆蓋前 | 是（邏輯只在 create_order 一次） | 整合 | 否 | 自動 |
| `wp_kses_post` 移除合法標籤 | 合約失真 | 部分（限於 KSES 允許清單） | 整合（XSS payload） | 是 | 後台調整內容 |

## 實作步驟

---

### 第一階段：專案骨架與相依（Foundation）

**目標**：可被 WP 啟用、HPOS 相容已宣告、無致命錯誤，後台 Plugins 頁顯示「Power Agreement」。

1. **建立 plugin bootstrap**（檔案：`power-agreement.php`）
   - 行動：寫 plugin header（Name, URI, Version 0.1.0, Requires PHP 8.1, Requires at least 6.5, WC requires at least 8.3, Text Domain）；版本檢查 fail 則 admin notice + return；`before_woocommerce_init` 內 `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)`；require composer autoload；實例化 `Plugin`
   - 原因：所有後續邏輯的入口
   - 依賴：composer.json 已建
   - 風險：低

2. **Composer 設定**（檔案：`composer.json`）
   - 行動：`name`、PHP `^8.1`、PSR-4 `PowerAgreement\\` → `src/`；dev requires：`phpunit/phpunit ^9.6`、`wp-phpunit/wp-phpunit`、`yoast/phpunit-polyfills`、`szepeviktor/phpstan-wordpress`、`phpstan/phpstan ^1.10`、`wp-coding-standards/wpcs`、`automattic/woocommerce-sniffs`
   - 原因：autoload + dev 工具鏈
   - 風險：低

3. **package.json 設定**（檔案：`package.json`）
   - 行動：`@wordpress/scripts`、`@playwright/test`、`@wordpress/env`；scripts: `build` / `start` / `test:e2e` / `wp-env`
   - 風險：低

4. **`.wp-env.json`**（檔案：`.wp-env.json`）
   - 行動：兩個 env：`tests`（HPOS on）+ `tests-legacy`（HPOS off）；plugins: `[".", "https://downloads.wordpress.org/plugin/woocommerce.zip"]`
   - 原因：local 與 CI 共用
   - 風險：中（wp-env config 的 HPOS 切換需用 `lifecycleScripts` + WC CLI）

5. **`Plugin` 主類別**（檔案：`src/Plugin.php`）
   - 行動：`__construct` 接受 `string $pluginFile`；`run()` 方法在 `init` priority 0 註冊：`Settings\SettingsPage::register()`、`Checkout\ClassicCheckout::register()`、`Checkout\BlockCheckout::register()`、`Order\OrderAdminDisplay::register()`；`init` priority 5 載入 textdomain
   - 原因：集中註冊 hook，便於測試替換
   - 風險：低

6. **`Compat\Requirements`**（檔案：`src/Compat/Requirements.php`）
   - 行動：static `check( array $requirements ): WP_Error|true`；可同時驗 PHP / WP / WC
   - 風險：低

7. **`Compat\HposCompat`**（檔案：`src/Compat/HposCompat.php`）
   - 行動：包裝 `OrderUtil::custom_orders_table_usage_is_enabled()` 與 declare_compatibility
   - 風險：低

8. **`uninstall.php`**（檔案：`uninstall.php`）
   - 行動：`defined('WP_UNINSTALL_PLUGIN') || exit;` + `delete_option('power_agreement_settings')`；不刪 order meta
   - 原因：法律證據保留
   - 風險：低

9. **`readme.txt`**（檔案：`readme.txt`）
   - 行動：基本 wp.org readme（Stable tag, License, Description, Changelog）
   - 風險：低

**驗證**：
- `composer install` 無錯
- `wp-env start` 成功，外掛可啟用
- WC 後台 Status → System Status → 確認外掛被列為 HPOS-compatible
- `wp plugin activate power-agreement` 無 fatal

---

### 第二階段：Settings 模組（Admin only）

**目標**：後台可設定合約啟用狀態、標題、內容、同意文字並持久化；可透過 Repository 讀回 typed 值。

1. **`SettingsRepository`**（檔案：`src/Settings/SettingsRepository.php`）
   - 行動：
     - `const OPTION = 'power_agreement_settings';`
     - `defaults(): array` — 回 `['enabled'=>false, 'title'=>__('Agreement','power-agreement'), 'content'=>'', 'consent_text'=>__('I have read and agree to the agreement above.','power-agreement')]`
     - `settings(): array` — `wp_parse_args( get_option( self::OPTION, [] ), self::defaults() )`
     - `isEnabled(): bool` / `title(): string` / `content(): string` / `consentText(): string`
     - `save( array $raw ): bool` — sanitize + `update_option`
     - `sanitize( array $raw ): array` — `enabled`→bool, `title`→`mb_substr(sanitize_text_field, 0, 100)`, `content`→`wp_kses_post`, `consent_text`→`mb_substr(sanitize_text_field, 0, 200)`
   - 原因：集中 sanitize + 預設值，方便測試
   - 風險：低

2. **`SettingsPage`**（檔案：`src/Settings/SettingsPage.php`）
   - 行動：
     - `register(): void` — 掛 `admin_menu` 與 `admin_init`
     - `add_menu(): void` — `add_submenu_page('woocommerce', 'Power Agreement', 'Power Agreement', 'manage_woocommerce', 'power-agreement', [$this,'render'])`
     - `register_settings(): void` — `register_setting('power_agreement', SettingsRepository::OPTION, ['sanitize_callback'=>[$this,'sanitize']])` + `add_settings_section` + 4 個 `add_settings_field`（其中 content 用 `wp_editor`）
     - `enqueue_editor( $hook ): void` — 在自家頁面 hook 上 `wp_enqueue_editor()`
     - `render()` — 標準 settings page wrap
     - `field_*` — 各欄位 callback
   - 原因：標準 WP Settings API 工作流
   - 風險：中（wp_editor 整合）

3. **PHPUnit 測試**（檔案：`tests/Integration/Settings/SettingsRepositoryTest.php`）
   - case：預設值正確、儲存後讀回、XSS payload `<script>alert(1)</script>` 經 sanitize 後 `<script>` 被移除但 `<a href>` 保留、超長 title 被截、enabled bool round-trip
   - 風險：低

**驗證**：
- `wp-env run tests-cli wp option get power_agreement_settings` 在儲存後可看到正確值
- 後台選單出現 WooCommerce → Power Agreement
- TinyMCE 編輯器可載入並儲存

---

### 第三階段：Order Domain（值物件 + 寫入 + 讀取 + 後台顯示）

**目標**：在不接結帳的前提下，能對任意 `WC_Order` 寫入 / 讀取同意紀錄，並在訂單編輯頁顯示。

1. **`AgreementSnapshot`**（檔案：`src/Order/AgreementSnapshot.php`）
   - 行動：
     - `final readonly class AgreementSnapshot`
     - `__construct( public string $html, public string $hash, public string $agreedAt, public string $ip )`
     - `static captureNow( string $html, string $ip ): self` — `hash('sha256', $html)`、`gmdate('c')`
     - `writeTo( WC_Order $order ): void` — 4 次 `update_meta_data`
     - `static readFrom( WC_Order $order ): ?self` — 4 次 `get_meta`，缺一回 null
   - 風險：低

2. **`ConsentValidator`**（檔案：`src/Checkout/ConsentValidator.php`）
   - 行動：
     - `__construct( SettingsRepository $repo )`
     - `shouldEnforce(): bool` — `enabled && content !== ''`
     - `errorMessage(): string` — 返回 sprintf 樣板
   - 風險：低

3. **`OrderMetaWriter`**（檔案：`src/Order/OrderMetaWriter.php`）
   - 行動：
     - `__construct( SettingsRepository $repo )`
     - `writeFromRequest( WC_Order $order ): void` — 取目前 settings.content + IP → `AgreementSnapshot::captureNow()` → `writeTo($order)`
   - 原因：抽出，讓 Classic 與 Block 共用
   - 風險：低

4. **`OrderAdminDisplay`**（檔案：`src/Order/OrderAdminDisplay.php`）
   - 行動：
     - `register()` 掛 `woocommerce_admin_order_data_after_order_details`
     - `render( WC_Order $order ): void` — `current_user_can('edit_shop_orders')` 檢查 → `AgreementSnapshot::readFrom()` → 渲染區塊（時間、IP、hash 前 16 碼、可展開全文）
   - 風險：低

5. **PHPUnit 測試**：
   - `tests/Integration/Order/AgreementSnapshotTest.php`：hash 確定性、ISO 時間格式、round-trip
   - `tests/Integration/Order/OrderMetaWriterTest.php`：建立 dummy order → write → 讀回 4 個 meta key
   - `tests/Integration/Order/OrderMetaWriterHposTest.php`：相同測試在 HPOS 模式下（透過 wp-env tests env）
   - `tests/Integration/Checkout/ConsentValidatorTest.php`：開關 / 內容空組合
   - 風險：中（HPOS 切換）

**驗證**：
- PHPUnit 兩個 matrix 全綠
- 手動建立訂單，呼叫 `writeFromRequest` → admin 訂單頁能看到資料

---

### 第四階段：Classic Checkout 整合

**目標**：使用 storefront 主題 + Classic checkout shortcode 時，accordion 顯示、勾選驗證、訂單建立後 meta 寫入。

1. **`ClassicCheckout`**（檔案：`src/Checkout/ClassicCheckout.php`）
   - 行動：
     - `__construct( SettingsRepository $repo, ConsentValidator $validator, OrderMetaWriter $writer )`
     - `register()`：
       - `add_action('wp_enqueue_scripts', [$this,'maybeEnqueueAssets'])` — 僅在 `is_checkout()` && validator->shouldEnforce()
       - `add_action('woocommerce_review_order_before_submit', [$this,'render'])`
       - `add_action('woocommerce_checkout_process', [$this,'validate'])`
       - `add_action('woocommerce_checkout_create_order', [$this,'persist'], 10, 2)`
     - `render()` — 輸出 accordion HTML（已 escape）
     - `validate()` — 若 `shouldEnforce` 且 `($_POST['power_agreement_consent'] ?? '') !== '1'` → `wc_add_notice( $validator->errorMessage(), 'error' )`
     - `persist( WC_Order $order, array $data )` — `shouldEnforce` 時呼叫 `writer->writeFromRequest($order)`
   - 風險：中（hook 順序與 nonce）

2. **前端 JS**（檔案：`assets/src/frontend/accordion.js`）
   - 行動：vanilla JS，DOMContentLoaded → 找 `[data-power-agreement]` → 綁 click toggle → 切 `aria-expanded` 與 class `is-open`；用 `data-power-agreement-initialized` 旗標避免重複綁
   - 風險：低

3. **CSS**（檔案：`assets/src/frontend/accordion.css`）
   - 行動：`.is-open .power-agreement__content { max-height: 50vh; overflow:auto; }`；transition；border + padding
   - 風險：低

4. **PHPUnit 測試**（檔案：`tests/Integration/Checkout/ClassicCheckoutTest.php`）
   - case：未勾選 → `wc_get_notices('error')` 含錯誤訊息
   - case：勾選 + 走 `WC_Checkout::create_order()` → 訂單 meta 4 個 key 都存在且符合預期
   - case：功能停用 → 不注入、無 notice、無 meta
   - 風險：中（模擬 `$_POST` + `WC()->session`）

5. **E2E 測試**（檔案：`tests/e2e/classic-checkout.spec.ts`）
   - case：加購商品 → 結帳頁 → 不勾選按下單 → 看到 error notice、URL 仍在 checkout
   - case：勾選 → 訂單成立、thankyou 頁出現
   - case：admin 登入 → 開該訂單 → 看到同意紀錄
   - 風險：中（需用 storefront / 預設主題避免 Classic 被 Block 取代）

**驗證**：
- PHPUnit + E2E 全綠
- 手動測試 storefront + Classic shortcode 流程

---

### 第五階段：Block Checkout 整合

**目標**：使用 WC Block Checkout（預設）時，accordion 渲染、checkbox state 與 Store API 同步、未勾選擋下、寫入 meta。

1. **`BlockCheckout` IntegrationInterface 實作**（檔案：`src/Checkout/BlockCheckout.php`）
   - 行動：
     - 實作 `Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface`：`get_name()` 回 `'power-agreement'`、`initialize()`、`get_script_handles()`、`get_editor_script_handles()`、`get_script_data()` 回設定值（title、content、consent_text、enabled）
     - `register()` 掛 `woocommerce_blocks_loaded`：
       - `add_action('woocommerce_blocks_checkout_block_registration', fn($r)=>$r->register($this))`
       - `add_action('woocommerce_blocks_loaded', [$this,'extendStoreApi'])`
       - `add_action('woocommerce_store_api_checkout_update_order_from_request', [$this,'validateConsent'], 10, 2)`
       - `add_action('woocommerce_store_api_checkout_update_order_meta', [$this,'persistMeta'])`
     - `extendStoreApi()` — `Package::container()->get(ExtendSchema::class)->register_endpoint_data([...endpoint=>'checkout', namespace=>'power-agreement', schema_callback, data_callback])`
     - `validateConsent( WC_Order $order, WP_REST_Request $request ): void` — 從 `$request['extensions']['power-agreement']['consent']` 讀；未勾選 + shouldEnforce → `throw new RouteException('power_agreement_required', $msg, 400)`
     - `persistMeta( WC_Order $order ): void` — 同 Classic 共用 writer
   - 風險：高（experimental API）

2. **Block 前端**（檔案：`assets/src/block-checkout/index.tsx`）
   - 行動：
     - `import { registerCheckoutBlock } from '@woocommerce/blocks-checkout'`
     - `registerCheckoutBlock({ metadata: blockJson, component: PowerAgreementBlock })`
     - 元件：`useState` 管展開、checkbox state 用 `useEffect` 呼叫 `extensionCartUpdate({ namespace:'power-agreement', data:{ consent } })`
     - 透過 `wp.hooks.addFilter('woocommerce_blocks_checkout_extensions_data')` 或從 `getSetting('power-agreement_data')` 讀後端傳來的 settings
   - 風險：高（Block API 學習曲線）

3. **`block.json`**（檔案：`assets/src/block-checkout/block.json`）
   - 行動：定義 block name `power-agreement/agreement`，parent 限定為 `woocommerce/checkout-fields-block`
   - 風險：低

4. **build pipeline**（檔案：`.wp-scripts.config.js` 或預設）
   - 行動：用 `@wordpress/scripts build` 預設即可；輸出至 `assets/build/`
   - 風險：低

5. **PHPUnit 測試**（檔案：`tests/Integration/Checkout/BlockCheckoutTest.php`）
   - case：用 `WC_REST_Unit_Test_Case`（或自製 wrapper）模擬 POST `/wc/store/v1/checkout` with `extensions.power-agreement.consent=false` → 期望 400 + code `power_agreement_required`
   - case：consent=true → 200 + 訂單 meta 寫入
   - 風險：高（Store API 測試樣板少）

6. **E2E 測試**（檔案：`tests/e2e/block-checkout.spec.ts`）
   - case：Block Checkout 頁未勾選送出 → checkbox 旁出現錯誤、訂單未成立
   - case：勾選送出 → 訂單成立 + admin 看到紀錄
   - 風險：中

**驗證**：
- 預設 WC Block Checkout 啟用時，PHPUnit + E2E 全綠
- 手動測：Block Checkout 切換到 Classic（停用 Cart/Checkout block 改用 shortcode）兩種狀態都 OK

---

### 第六階段：i18n / a11y / CI / 收斂

**目標**：i18n 完整、a11y 達 WCAG 2.1 AA、CI matrix 全綠、PHPStan/PHPCS 過、文件完整。

1. **i18n**：
   - 所有 user-facing 字串包 `__()` / `esc_html__()` / `esc_attr__()`
   - `Plugin::loadTextdomain()` on `init`
   - `composer require --dev wp-cli/i18n-command` + Makefile target `make pot` 執行 `wp i18n make-pot . languages/power-agreement.pot`
   - 提供 `languages/power-agreement-zh_TW.po`（手譯主要字串）+ `make mo`
   - **Block JS i18n**：`@wordpress/i18n` 的 `__('...', 'power-agreement')`，`wp_set_script_translations()` 在 `BlockCheckout::get_script_handles` 後呼叫

2. **a11y 收斂**：
   - 鍵盤操作測試：Tab 到 toggle → Enter / Space 開合 → Tab 到 checkbox → Space 勾選
   - axe-core 透過 `@axe-core/playwright` 跑在 E2E 中

3. **PHPStan**（檔案：`phpstan.neon.dist`）
   - level 8 + `szepeviktor/phpstan-wordpress` + `php-stubs/woocommerce-stubs`
   - bootstrap files: `vendor/php-stubs/woocommerce-stubs/woocommerce-stubs.php`

4. **PHPCS**（檔案：`phpcs.xml.dist`）
   - 規範：`WordPress-Extra` + `WooCommerce-Core`
   - text domain rule = `power-agreement`

5. **GitHub Actions CI**（檔案：`.github/workflows/ci.yml`）
   - jobs：
     - `lint`: PHPCS + PHPStan
     - `phpunit`: matrix `hpos: [on, off]`，跑 wp-env + PHPUnit
     - `e2e`: `npm run wp-env start` → `npm run build` → `npm run test:e2e`
     - `i18n`: `wp i18n make-pot` + 比對是否與 committed `.pot` 一致

6. **README + 開發文件**（檔案：`README.md`）
   - 目標讀者：開發者 / PR reviewer
   - 內容：how to dev (wp-env + npm), how to run tests, architecture overview pointing to `specs/`

7. **CHANGELOG**（檔案：`CHANGELOG.md`）
   - 0.1.0 初版

**驗證**：
- CI 全綠（lint + phpunit ×2 + e2e + i18n）
- axe-core 0 violations on checkout page
- 手動切換 zh_TW 後 UI 正確翻譯

---

## 測試策略

| 層級 | 工具 | 範圍 | 執行指令 |
|------|------|------|----------|
| 單元 | PHPUnit | `AgreementSnapshot` 等純 PHP 值物件 | `composer test:unit` |
| 整合 | PHPUnit + wp-env + WC | Settings、OrderMeta、Validator、Classic、Block REST | `composer test:integration`（matrix HPOS） |
| E2E | Playwright | Classic flow / Block flow / 後台流程 / a11y | `npm run test:e2e` |
| Lint | PHPCS / PHPStan | 全 src + tests | `composer lint` |

**關鍵邊界情況**（必含於測試）：
- 功能停用 → 不注入、無 notice、無 meta
- 啟用但內容空 → 後台 warning + 前台不注入
- 未勾選 → Classic notice / Block 400
- 勾選後成功 → 4 個 meta 全部寫入且 hash 對應 content
- HPOS on / off 切換
- 同一筆訂單只寫一次 meta
- 舊訂單（meta 缺）→ admin 顯示 fallback
- XSS payload → 內容被 sanitize

**測試命令**：
```bash
composer install
npm install
npm run wp-env start
composer lint
composer test
npm run test:e2e
```

## 依賴項目

- WordPress 6.5+
- WooCommerce 8.3+
- PHP 8.1+
- Node 18+
- Composer 2+
- Docker（wp-env 需要）

## 風險與緩解措施

- **高**：Block Checkout `IntegrationInterface` 與 Store API ExtendSchema 為相對較新且演進中的 API — 緩解：版本鎖定 + 防衛性 `class_exists` + 跟進 WC Blocks 文件 + 第一次接觸時花 30 min 跑通 hello-world 範例再正式實作
- **高**：HPOS 雙模式測試環境配置 — 緩解：`.wp-env.json` 兩組 + `lifecycleScripts` 自動切；CI matrix 跑兩次
- **中**：`wp_editor()` 在 Settings API 內整合的不穩定性 — 緩解：自寫 callback + 明確 enqueue
- **中**：Classic / Block Checkout 兩種模式互斥（無法同時啟用），測試需切換 — 緩解：E2E 用兩個獨立 spec，分別 set-up 不同主題 / 結帳模式
- **低**：`wp_kses_post` 對某些 HTML 結構過度保守 — 緩解：合約預期是文字 + 連結 + 圖片，不需 form/script，KSES 預設足夠

## 錯誤處理策略

採「擋使用者操作 + 不擋訂單成立」原則：
- 「使用者該知道」的錯誤（未勾選、設定無效）→ 阻斷 + UI 訊息
- 「使用者不需要知道」的錯誤（meta 寫入例外、IP 取不到）→ log + 默默 fallback，絕不阻擋訂單付款流程

具體機制：
- `wc_add_notice` for Classic
- `RouteException` for Block / Store API
- `wc_get_logger()->error( $msg, ['source' => 'power-agreement'] )` for 內部錯誤

## 限制條件

此計劃**不**包含：
- 多份合約 / 商品綁定
- 訂單 email PDF 附件
- IP 匿名化選項
- WPML / Polylang 整合
- 訂閱續訂（WC Subscriptions）情境的同意紀錄擴充
- 客戶端（個人 My Account 頁面）查看歷史同意紀錄
- 合約變更 audit log（誰在何時改了合約）
- wp.org 上架等級的 readme / banner / 截圖

這些列在 design doc §10「後續工作」，待主流程穩定後評估。

## 成功標準

- [ ] 第一階段：外掛可啟用、HPOS 已宣告相容、System Status 顯示綠色
- [ ] 第二階段：後台可儲存 4 欄位設定，TinyMCE 編輯器正常，PHPUnit 過
- [ ] 第三階段：可對 `WC_Order` 寫入 / 讀取 4 個 meta，HPOS / 非 HPOS 兩個 PHPUnit matrix 全綠
- [ ] 第四階段：Classic Checkout 流程：未勾選擋下、勾選下單、訂單頁顯示紀錄；E2E 過
- [ ] 第五階段：Block Checkout 流程同上；Store API 整合 PHPUnit 過；E2E 過
- [ ] 第六階段：CI 全綠（lint + phpunit×2 + e2e + i18n）；axe-core 0 violations；繁中翻譯可用

## 預估複雜度：中

- 第一、二、三階段為標準 WP 開發，難度低
- 第四階段為 WC Classic Checkout 標準 hook 用法，難度中低
- 第五階段為主要技術挑戰（Block API + Store API 整合），單獨難度高
- 第六階段為收斂工作，難度中

整體節奏建議：每階段一個 PR / commit batch，第五階段可拆 sub-tasks（IntegrationInterface 註冊、Schema 註冊、React 元件、validation hook、persist hook 各一）。
