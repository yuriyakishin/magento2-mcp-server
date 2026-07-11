<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Store;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Yu\McpServer\Model\ToolInterface;

/**
 * The store's "passport": background facts an assistant needs in almost every
 * conversation — currencies, locale, shipping/payment options, contacts. Everything here
 * is already visible to a storefront visitor (checkout shows the methods, the footer
 * shows the contacts), hence public. Deliberately exposes only code + title of payment
 * and shipping methods — never their configuration, which can contain API credentials.
 */
class GetStoreInfo implements ToolInterface
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'store_info';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Returns general store facts: store name, base URL, base and allowed '
            . 'currencies, locale and timezone, active shipping and payment methods '
            . '(code + customer-facing title), and public contact details. Use it for '
            . 'background context: currency conversions, "how can I pay?", "do you '
            . 'deliver?", contact questions.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    /**
     * Storefront-visible facts only — no ACL resource required.
     */
    public function getRequiredAclResource(): ?string
    {
        return null;
    }

    /**
     * @param array $arguments Takes no arguments.
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $store = $this->storeManager->getStore();

        return [
            'store' => [
                'name' => $store->getName(),
                'base_url' => $store->getBaseUrl(),
                'base_currency' => $store->getBaseCurrencyCode(),
                'display_currency' => $store->getDefaultCurrencyCode(),
                'allowed_currencies' => array_values($store->getAvailableCurrencyCodes()),
                'locale' => (string) $this->configValue('general/locale/code'),
                'timezone' => (string) $this->configValue('general/locale/timezone'),
            ],
            'contact' => [
                'store_name' => $this->configValue('general/store_information/name'),
                'phone' => $this->configValue('general/store_information/phone'),
                'email' => $this->configValue('trans_email/ident_general/email'),
                'country' => $this->configValue('general/store_information/country_id'),
                'city' => $this->configValue('general/store_information/city'),
                'street' => $this->configValue('general/store_information/street_line1'),
            ],
            'shipping_methods' => $this->activeMethods('carriers'),
            'payment_methods' => $this->activeMethods('payment'),
        ];
    }

    /**
     * Lists the active methods of a config section as code + customer-facing title.
     * Only these two fields leave the server: the rest of a method's configuration
     * (gateway URLs, merchant ids, API keys) must never be exposed.
     *
     * @param string $section "carriers" or "payment"
     * @return array<int, array{code: string, title: string}>
     */
    private function activeMethods(string $section): array
    {
        $config = $this->configValue($section);
        if (!is_array($config)) {
            return [];
        }

        $methods = [];
        foreach ($config as $code => $data) {
            if (!is_array($data) || !($data['active'] ?? false)) {
                continue;
            }
            $methods[] = [
                'code' => (string) $code,
                'title' => (string) ($data['title'] ?? $code),
            ];
        }

        return $methods;
    }

    /**
     * Reads a config value in the current store scope.
     */
    private function configValue(string $path): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
    }
}
