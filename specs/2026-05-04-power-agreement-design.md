# Power Agreement — 設計文件

- **建立日期**：2026-05-04
- **外掛 slug / 文字網域**：`power-agreement`
- **PHP namespace**：`PowerAgreement`
- **最低支援版本**：WordPress 6.5+ / WooCommerce 8.3+ / PHP 8.1+
- **HPOS 相容**：是（宣告 `custom_order_tables` 相容）

---

## 1. 目標與範疇

在 WooCommerce 結帳頁的「下單」按鈕**之前**，加入一個可摺疊的合約區塊與「同意」勾選框。使用者必須勾選同意後才能完成結帳。每筆訂單都會保留下單當下的合約完整快照，作為法律佐證。

**功能範圍**：
- 後台單一固定合約（全站共用一份），透過設定頁維護。
- Classic Checkout 與 Block Checkout 雙支援。
- 合約預設摺疊，點擊展開。
- 未勾選同意時，提交結帳會被擋下並顯示錯誤訊息（行為比照 WC 內建 Terms and conditions）。
- 訂單成立時寫入 4 個 order meta（HPOS-safe）：合約 HTML 快照、SHA-256、同意時間、IP。
- 訂單後台編輯頁顯示同意紀錄。

**範圍外（不在本次實作）**：
- 多份合約 / 依商品綁定不同合約
- 訂單完成 email 附加合約 PDF
- 合約版本管理表（採「快照存於訂單」策略，不需版本表）
- 多語系翻譯外掛（WPML / Polylang）特殊整合

---

## 2. 整體架構

### 2.1 啟動流程（`power-agreement.php` bootstrap）

1. 檢查 PHP 8.1 / WP 6.5 / WooCommerce 啟用，未滿足則 admin notice + early return。
2. Composer PSR-4 autoload。
3. 在 `before_woocommerce_init` hook 內呼叫 `FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true )` 宣告 HPOS 相容。
4. 實例化 `Plugin` 主類，由它註冊所有 hook 與依賴注入。

### 2.2 模組分層（DDD-lite）

| 模組 | 責任 |
|------|------|
| `Settings/` | 後台設定頁、`wp_options` 讀寫 |
| `Checkout/` | Classic + Block Checkout 注入與驗證 |
| `Order/` | 訂單 meta 寫入、值物件、後台顯示 |
| `Compat/` | 版本檢查、HPOS 相容包裝 |

### 2.3 檔案結構

```
power-agreement/
├── power-agreement.php          # bootstrap：版本檢查 + HPOS 宣告 + 啟動 Plugin
├── readme.txt                   # WP 外掛 readme（標準 wp.org 格式）
├── uninstall.php                # 解除安裝清理（只刪 options，保留訂單 meta）
├── composer.json                # PSR-4: PowerAgreement\\ -> src/
├── package.json                 # @wordpress/scripts 建置 Block 前端
├── src/
│   ├── Plugin.php               # 主類，註冊所有 hooks
│   ├── Settings/
│   │   ├── SettingsPage.php     # admin 選單頁 + Settings API + wp_editor
│   │   └── SettingsRepository.php   # 集中讀寫 options，提供 typed getter
│   ├── Checkout/
│   │   ├── ClassicCheckout.php  # 注入 Classic 結帳 + woocommerce_checkout_process 驗證
│   │   ├── BlockCheckout.php    # IntegrationInterface 註冊 + Store API 驗證
│   │   └── ConsentValidator.php # 共用：判斷是否啟用、是否該擋下
│   ├── Order/
│   │   ├── AgreementSnapshot.php   # 合約 HTML 快照值物件（hash、html、agreed_at、ip）
│   │   ├── OrderMetaWriter.php     # HPOS-safe 寫入訂單 meta
│   │   └── OrderAdminDisplay.php   # 訂單頁同意紀錄顯示
│   └── Compat/
│       ├── Requirements.php     # WP/WC/PHP 版本檢查
│       └── HposCompat.php       # OrderUtil 包裝
├── assets/
│   ├── src/
│   │   ├── block-checkout/      # Block Checkout integration（JS + checkbox block）
│   │   └── frontend/accordion.css   # accordion 樣式（兩種結帳共用）
│   └── build/                   # @wordpress/scripts 輸出
├── languages/
│   ├── power-agreement.pot
│   └── power-agreement-zh_TW.po / .mo
├── tests/
│   ├── Integration/             # PHPUnit + wp-env
│   └── e2e/                     # Playwright（Classic + Block 兩條流程）
└── specs/
    └── 2026-05-04-power-agreement-design.md
```

### 2.4 外部相依

- **Runtime**：無（保持輕量，只依 WC / WP 提供之 API）
- **Dev**：
  - `phpunit/phpunit ^9.6`
  - `wp-phpunit/wp-phpunit`
  - `wp-coding-standards/wpcs`
  - `szepeviktor/phpstan-wordpress`
  - `phpstan/phpstan ^1.10` (level 8)
  - `@wordpress/scripts`（Block JS 建置）
  - `@playwright/test`（E2E）

---

## 3. 資料模型

### 3.1 後台設定（`wp_options`）

單一 option key：`power_agreement_settings`（陣列序列化儲存）。

| 欄位 | 型別 | 說明 | 預設值 |
|------|------|------|--------|
| `enabled` | bool | 是否在結帳啟用 | `false` |
| `title` | string | 顯示在 accordion 標題的文字 | `__( 'Agreement', 'power-agreement' )` |
| `content` | string (HTML) | 富文本合約內容 | `''` |
| `consent_text` | string | 勾選框旁的同意文字 | `__( 'I have read and agree to the agreement above.', 'power-agreement' )` |

> **預設值的 i18n 取得**：因為 `register_setting` 的 `default` 不能寫成函式呼叫，預設值在 `SettingsRepository::defaults()` 內以 `__()` 動態組裝（每次讀取時 resolve 當前語系），而非寫死於 DB。

**錯誤訊息策略**：未勾選時的錯誤訊息採固定樣板 `sprintf( __( 'Please check "%s" to place your order.', 'power-agreement' ), $consent_text )`，不另設欄位以保持設定頁簡潔。

### 3.2 訂單同意紀錄（HPOS-safe order meta）

每筆訂單建立時寫入 4 個 meta key，皆透過 `$order->update_meta_data()` 寫入，由 WC 自動處理 HPOS / legacy 兩套儲存。

| Meta key | 型別 | 內容 |
|----------|------|------|
| `_power_agreement_html` | string | 下單當下完整合約 HTML（已過 `wp_kses_post`） |
| `_power_agreement_hash` | string | 上述 HTML 經 `hash( 'sha256', $html )` 的 hex 字串 |
| `_power_agreement_agreed_at` | string | 同意時間，ISO 8601（`gmdate( 'c' )`，UTC） |
| `_power_agreement_ip` | string | 下單者 IP（`WC_Geolocation::get_ip_address()`，**完整保存**作為法律佐證） |

底線開頭代表 protected meta，不會出現在預設 `Custom Fields` metabox。

### 3.3 值物件 `AgreementSnapshot`

集中四個欄位的不可變物件，便於測試與序列化：

```php
final readonly class AgreementSnapshot {
    public function __construct(
        public string $html,
        public string $hash,
        public string $agreedAt,
        public string $ip,
    ) {}

    public static function captureNow( string $html, string $ip ): self;
    public function writeTo( WC_Order $order ): void;       // update_meta_data ×4
    public static function readFrom( WC_Order $order ): ?self;  // get_meta ×4，缺一即回 null
}
```

### 3.4 解除安裝行為（`uninstall.php`）

- 刪除 `power_agreement_settings` option。
- **不刪除** order meta（法律證據必須保留；使用者重新安裝後也能讀回）。
- 不刪除 transient（外掛無使用 transient）。

---

## 4. UI 與互動

### 4.1 後台設定頁

**位置**：WP admin → **WooCommerce → Power Agreement**（`add_submenu_page` 掛在 `woocommerce` 父選單）。

**權限**：`manage_woocommerce`。

**欄位（由上到下）**：
1. **啟用結帳合約**（Checkbox）— 取消勾選即停用功能，不需 deactivate 整個外掛。
2. **合約標題**（Text input，max 100 字）— 顯示在 accordion 標題列。
3. **合約內容**（`wp_editor()` TinyMCE 富文本，媒體按鈕關閉）— 主要合約文字。
4. **同意文字**（Text input，max 200 字）— 勾選框旁邊的文字。

**儲存**：標準 Settings API（`register_setting` + `settings_fields()` + `do_settings_sections()`），自動含 nonce。`sanitize_callback` 內處理：
- `enabled` → `rest_sanitize_boolean`
- `title` / `consent_text` → `sanitize_text_field` + `mb_substr` 截長
- `content` → `wp_kses_post`

### 4.2 前端 Classic Checkout

**注入點**：Hook `woocommerce_review_order_before_submit`（位於下單按鈕正上方）。

**HTML 結構**：

```html
<div class="power-agreement" data-power-agreement>
  <button type="button" class="power-agreement__toggle" aria-expanded="false"
          aria-controls="power-agreement-content">
    <span class="power-agreement__title">{title}</span>
    <span class="power-agreement__chevron" aria-hidden="true">▾</span>
  </button>
  <div id="power-agreement-content" class="power-agreement__content" hidden role="region">
    {content_html}
  </div>
  <label class="power-agreement__consent">
    <input type="checkbox" name="power_agreement_consent" value="1" />
    <span>{consent_text}</span>
  </label>
</div>
```

**互動 JS**：vanilla JS（無 jQuery 依賴），點擊 toggle 切換 `aria-expanded` 與 `hidden`，CSS `max-height` transition 動畫。

**驗證**：Hook `woocommerce_checkout_process` → 啟用 + `$_POST['power_agreement_consent']` 不為 `'1'` → `wc_add_notice( $msg, 'error' )`。

**寫入**：Hook `woocommerce_checkout_create_order`（priority 10）→ `AgreementSnapshot::captureNow()` + `writeTo($order)`。寫在 `$order->save()` 前讓 WC 一次刷盤。

### 4.3 前端 Block Checkout

**註冊**：實作 `Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface`，掛 `woocommerce_blocks_checkout_block_registration`。

**前端**：JS bundle 渲染 React 元件（與 Classic 同樣的 accordion + checkbox），透過 `extensionCartUpdate({ namespace: 'power-agreement', data: { consent: bool } })` 與後端同步。

**Schema 註冊**：`ExtendSchema::register_endpoint_data()` 在 `checkout` endpoint 註冊 `power-agreement` extension namespace，前後端走官方契約。

**Store API 驗證**：Hook `woocommerce_store_api_checkout_update_order_from_request`：

```php
$consent = $request->get_param( 'extensions' )['power-agreement']['consent'] ?? false;
if ( ! $consent ) {
    throw new RouteException( 'power_agreement_required', $msg, 400 );
}
```

**寫入**：Hook `woocommerce_store_api_checkout_update_order_meta` → 共用 `OrderMetaWriter`。

### 4.4 訂單後台顯示

**位置**：訂單編輯頁，hook `woocommerce_admin_order_data_after_order_details`（HPOS 與 legacy 雙觸發）。

**顯示內容**：

```
─── 合約同意紀錄 ─────────────
✓ 同意時間：2026-05-04 14:32:18 UTC
✓ 客戶 IP：203.0.113.45
✓ 合約 SHA-256：a1b2c3d4… (前 16 碼)
[查看當下合約全文 ▾]   ← 點開展示完整 HTML 快照（dialog 或 inline）
```

**權限**：`edit_shop_orders`。

### 4.5 樣式

- 單檔 CSS `accordion.css`，由 Classic 與 Block 共用。
- 不引入第三方 CSS 框架；`currentColor` 與 inherit 字型，與 theme 自然融合。
- 僅控制邊框 / 間距 / 過渡（`max-height` transition）。

---

## 5. 安全模型

| 面向 | 措施 |
|------|------|
| **能力檢查** | 後台設定頁：`manage_woocommerce`；訂單頁顯示：`edit_shop_orders` |
| **Nonce** | Settings API 自動處理；Block 走 Store API 內建 nonce；Classic 仰賴 WC 結帳本身的 nonce |
| **輸入清洗** | 合約內容：`wp_kses_post`；標題/同意文字：`sanitize_text_field`；勾選狀態：嚴格比對 `'1'` 或 `rest_sanitize_boolean` |
| **輸出轉義** | 標題：`esc_html`；合約內容（包含訂單頁快照）：`wp_kses_post`；屬性：`esc_attr`；URL：`esc_url` |
| **CSRF** | WC 結帳 form 內建 nonce + Store API token |
| **SQL 注入** | 全程 WC API（`update_meta_data` / `get_meta`）與 Settings API，無原生 SQL |
| **XSS（合約快照）** | 訂單頁顯示「過去某時刻的 HTML」時仍走 `wp_kses_post`，防範 KSES 規則演進造成的歷史資料風險 |
| **直接存取防護** | 所有 PHP 檔頂端 `defined( 'ABSPATH' ) || exit;` |
| **HPOS 安全** | 一律走 `$order->update_meta_data()` / `$order->get_meta()`，不直接寫 `wp_postmeta` |

---

## 6. 錯誤處理登記表

| 失敗模式 | 偵測點 | 行為 |
|---------|--------|------|
| WooCommerce 未啟用 / 版本太舊 | `plugins_loaded` (priority 20) `class_exists('WooCommerce')` + `WC_VERSION` 比對 | admin notice，外掛不註冊任何 hook |
| PHP < 8.1 | `power-agreement.php` 開頭 `version_compare` | admin notice + early return |
| WP < 6.5 | 同上比對 `$wp_version` | admin notice + early return |
| 設定為「停用」 | `ConsentValidator::isEnabled()` | 不注入 UI，不驗證，不寫 meta（透明 no-op） |
| 合約內容為空 | `SettingsRepository::content() === ''` | 後台 warning「合約啟用但內容為空」；前端略過注入 |
| Classic 未勾選提交 | `woocommerce_checkout_process` | `wc_add_notice( $msg, 'error' )` |
| Block 未勾選提交 | Store API extension validator | `RouteException` 回 400 + JSON error |
| HPOS API 不存在（極舊 WC） | `function_exists( OrderUtil::class . '::custom_orders_table_usage_is_enabled' )` | 回退用 `$order->update_meta_data`（仍 HPOS-safe） |
| `WC_Geolocation` 取不到 IP | `get_ip_address()` 回空字串 | meta 寫 `'unknown'`，不阻斷下單 |
| Block JS bundle 載入失敗 | 前端 console error | Block 結帳缺 accordion；後端驗證仍會擋下，無法繞過（防作弊以伺服器為準） |

---

## 7. 測試策略

### 7.1 Integration（PHPUnit + wp-env，主力）

- `SettingsRepositoryTest` — 預設值、儲存、清洗（XSS payload 經 `wp_kses_post` 後保留 `<a>` 但去除 `<script>`）
- `OrderMetaWriterTest` — 4 個 meta key round-trip、HPOS / 非 HPOS 雙模式、`AgreementSnapshot` 序列化
- `ConsentValidatorTest` — 啟用 / 停用 / 內容空 / 勾選狀態組合
- `ClassicCheckoutTest` — 模擬 `$_POST` + 觸發 `woocommerce_checkout_process` → 斷言 notice 與 meta 寫入
- `BlockCheckoutTest` — 用 `WC_REST_Unit_Test_Case` 模擬 Store API request，斷言 extension validate 與 meta 寫入

### 7.2 E2E（Playwright，覆蓋核心使用者旅程）

1. **Classic Checkout**：商品加入購物車 → 結帳頁 → 不勾選送出 → 看到錯誤 notice → 勾選送出 → 訂單成立 → 後台訂單頁看到同意紀錄
2. **Block Checkout**：同上但走 Block 結帳頁
3. **後台設定頁**：登入 → 切換啟用 → 編輯合約 → 儲存 → 前台看到變化

### 7.3 Unit（最少，僅值物件）

- `AgreementSnapshot` 的 hash 計算與 ISO 8601 時間格式

### 7.4 CI

- `wp-env` 跑 PHPUnit（matrix：HPOS on / off）
- Playwright（用 wp-env 起站）
- PHPStan level 8 + WordPress-Stubs
- WPCS（WordPress-Extra ruleset）

---

## 8. i18n

- 文字網域：`power-agreement`，所有可見字串包 `__()` / `esc_html__()` / `esc_attr__()`。
- `load_plugin_textdomain( 'power-agreement', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' )` 掛 `init`。
- CI 用 `wp i18n make-pot` 自動產生 `.pot`。
- 預設 UI 字串為英文，附 `power-agreement-zh_TW.po` 繁中翻譯。

---

## 9. Accessibility

- Accordion：`<button aria-expanded aria-controls>` + 對應 `<div id role="region">`，鍵盤可操作。
- Checkbox 與 label 用 `<label>` 包裹，點文字也能勾。
- 錯誤 notice 走 WC 既有 `wc_add_notice`，自帶 `role="alert"`。
- Color contrast 不寫死，繼承 theme 確保符合 WCAG 2.1 AA。

---

## 10. 後續工作（非本次範圍）

待主流程穩定後可考慮：
- 多份合約 + 商品/類別綁定
- 訂單完成 email 附合約 PDF（搭配 `pdf-lib` 或 `dompdf`）
- IP 匿名化選項（GDPR 嚴格情境）
- 訂閱續訂（WC Subscriptions）情境的同意紀錄
- WPML / Polylang 多語系合約整合
