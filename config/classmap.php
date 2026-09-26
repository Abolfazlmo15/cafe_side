<?php
// config/classmap.php
// =============================================================
// Class name → absolute file path.
//
// When you add a new class, add one line here. The autoloader
// in bootstrap.php does the rest.
//
// Procedural files (api.php, auth.php, urls.php, database.php,
// config.php) are NOT in this map — they're loaded explicitly by
// bootstrap.php because they define functions, not classes.

return [
    // ── Root src ─────────────────────────────────────────────
    'EnvLoader'            => SRC_PATH . '/EnvLoader.php',

    // ── AI · providers ───────────────────────────────────────
    'ProviderBase'         => SRC_PATH . '/ai/providers/ProviderBase.php',
    'OpenAICompatProvider' => SRC_PATH . '/ai/providers/OpenAICompatProvider.php',
    'OpenRouterProvider'   => SRC_PATH . '/ai/providers/OpenRouterProvider.php',
    'GroqProvider'         => SRC_PATH . '/ai/providers/GroqProvider.php',
    'DeepSeekProvider'     => SRC_PATH . '/ai/providers/DeepSeekProvider.php',
    'MistralProvider'      => SRC_PATH . '/ai/providers/MistralProvider.php',
    'TogetherProvider'     => SRC_PATH . '/ai/providers/TogetherProvider.php',
    'HuggingFaceProvider'  => SRC_PATH . '/ai/providers/HuggingFaceProvider.php',
    'ProviderRegistry'     => SRC_PATH . '/ai/providers/ProviderRegistry.php',

    // ── AI · managers ────────────────────────────────────────
    'AIBlacklist'          => SRC_PATH . '/ai/managers/AIBlacklist.php',
    'AIModelCache'         => SRC_PATH . '/ai/managers/AIModelCache.php',
    'AIHealthChecker'      => SRC_PATH . '/ai/managers/AIHealthChecker.php',
    'AIRateLimiter'        => SRC_PATH . '/ai/managers/AIRateLimiter.php',

    // ── AI · bridge, memory, prompts, utils ──────────────────
    'AIBridge'             => SRC_PATH . '/ai/bridge/AIBridge.php',
    'ReportMemory'         => SRC_PATH . '/ai/memory/ReportMemory.php',
    'PromptLibrary'        => SRC_PATH . '/ai/prompts/PromptLibrary.php',
    'HttpClient'           => SRC_PATH . '/ai/utils/HttpClient.php',

    // ── Analytics ────────────────────────────────────────────
    'AnalyticsEngine'      => SRC_PATH . '/analytics/AnalyticsEngine.php',
    'AnalyticsCache'       => SRC_PATH . '/analytics/AnalyticsCache.php',

    // ── Analytics · reports ─────────────────────────────────
    'ReportBase'           => SRC_PATH . '/analytics/reports/ReportBase.php',
    'RevenueTrendReport'   => SRC_PATH . '/analytics/reports/RevenueTrendReport.php',
    'TopItemsReport'       => SRC_PATH . '/analytics/reports/TopItemsReport.php',
    'LeastItemsReport'     => SRC_PATH . '/analytics/reports/LeastItemsReport.php',
    'HourlyHeatmapReport'  => SRC_PATH . '/analytics/reports/HourlyHeatmapReport.php',
    'ItemCombosReport'     => SRC_PATH . '/analytics/reports/ItemCombosReport.php',
    'FadingItemsReport'    => SRC_PATH . '/analytics/reports/FadingItemsReport.php',
    'RisingItemsReport'    => SRC_PATH . '/analytics/reports/RisingItemsReport.php',
    'PriceTierShiftReport' => SRC_PATH . '/analytics/reports/PriceTierShiftReport.php',
    'AnomalyReport'        => SRC_PATH . '/analytics/reports/AnomalyReport.php',
    // 'ProxyPool' => SRC_PATH . '/ai/proxy/ProxyPool.php',

    // ── CLI framework ────────────────────────────────────────
    'Output'               => SRC_PATH . '/cli/Output.php',
    'Worker'               => SRC_PATH . '/cli/Worker.php',
    'Registry'             => SRC_PATH . '/cli/Registry.php',

    // ── Helpers ──────────────────────────────────────────────
    'Jalali'               => SRC_PATH . '/helpers/Jalali.php',

    // ── Layout ───────────────────────────────────────────────
    'AdminLayout'          => SRC_PATH . '/layout/AdminLayout.php',
    'ClientLayout'         => SRC_PATH . '/layout/ClientLayout.php',

    // ── Migrations base class ───────────────────────────────
    'Migration'            => SRC_PATH . '/migrations/Migration.php',

    // ── Utils ────────────────────────────────────────────────
    'CronDispatcher'       => UTILS_PATH . '/CronDispatcher.php',
    
    'ChatPromptLibrary' => SRC_PATH . '/ai/chat/ChatPromptLibrary.php',
    'IntentClassifier'  => SRC_PATH . '/ai/chat/IntentClassifier.php',
    
    'IntentHandler' => SRC_PATH . '/ai/chat/IntentHandler.php',
    'ResponseNarrator' => SRC_PATH . '/ai/chat/ResponseNarrator.php',

];