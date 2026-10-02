<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_radicalmart_search
 *
 * @copyright   (C) 2025-2026 Dharma Design
 * @license     GNU General Public License version 2 or later
 */

namespace Joomla\Module\RadicalMartSearch\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\RadicalMart\Site\Helper\RouteHelper;
use Joomla\Registry\Registry;

class RadicalMartSearchHelper
{
	/**
	 * Method to get catalog page URL.
	 *
	 * @param   Registry  $params  Module params.
	 *
	 * @throws  \Exception
	 *
	 * @return  string  The catalog URL.
	 *
	 * @since  1.0.0
	 */
	public static function getCatalogUrl(Registry $params): string
	{
		if ((int) $params->get('menu_item') > 0)
		{
			$link = 'index.php?Itemid=' . (int) $params->get('menu_item');
		}
		else
		{
			// Глобальный поиск: всегда ведём на корневую категорию
			$link = RouteHelper::getCategoryViewRoute(1);
		}

		return Route::link('site', $link);
	}
	/**
	 * AJAX method for searching products
	 *
	 * @return  array  Search results
	 *
	 * @since  1.0.0
	 */
	public static function getAjax(): array
	{
		$app   = Factory::getApplication();
		$input = $app->getInput();
		$query = trim($input->getString('query', ''));
		$limit = min(50, max(1, $input->getInt('limit', 10)));

		if (empty($query) || mb_strlen($query) < 2)
		{
			return [
				'success' => false,
				'items'   => [],
				'message' => 'Запрос должен содержать минимум 2 символа'
			];
		}

		try
		{
			// Load language
			$app->getLanguage()->load('com_radicalmart');

			// Стандартный поиск RadicalMart не включает поле `code`, поэтому
			// сначала получаем совпадения по артикулу и выводим их первыми.
			$normalizedCode = self::normalizeCode($query);
			$codeIds = self::getProductIdsByCode($normalizedCode, $limit);
			$titleIds = self::getProductIdsByTitle($query, $limit);
			$items    = [];

			// Для подсказок ищем по артикулу и названию. Поиск по описанию и
			// характеристикам давал нерелевантную спецтехнику: в её описаниях
			// встречалось слово «бетон».
			foreach (array_merge(self::getProductsByIds($codeIds), self::getProductsByIds($titleIds)) as $item)
			{
				if (!isset($items[(int) $item->id]) && count($items) < $limit)
				{
					$items[(int) $item->id] = $item;
				}
			}

			$items = array_values($items);

			$results = [];
			if (!empty($items))
			{
				foreach ($items as $item)
				{
					// Используем уже сформированную ссылку из модели
					$productLink = !empty($item->link) ? $item->link : '#';
					
					// Проверяем нужно ли добавить домен (только если ссылка не начинается с http)
					if ($productLink !== '#' && strpos($productLink, 'http://') !== 0 && strpos($productLink, 'https://') !== 0)
					{
						// Ссылка относительная, добавляем полный URL
						$uri = Uri::getInstance();
						$baseUrl = $uri->toString(['scheme', 'host', 'port']);
						$productLink = $baseUrl . (strpos($productLink, '/') === 0 ? '' : '/') . $productLink;
					}

					// Get image
					$image = '';
					if (!empty($item->image))
					{
						$image = $item->image;
					}
					elseif (!empty($item->images) && is_array($item->images) && !empty($item->images[0]))
					{
						$image = $item->images[0];
					}
					
					// Добавляем домен к изображению если нужно
					if (!empty($image) && strpos($image, 'http://') !== 0 && strpos($image, 'https://') !== 0)
					{
						$uri = Uri::getInstance();
						$baseUrl = $uri->toString(['scheme', 'host', 'port']);
						$image = $baseUrl . '/' . ltrim($image, '/');
					}

					// Get price. final_string also lets pricing plugins return text
					// such as "Цена по запросу" instead of an artificial zero price.
					$price       = '';
					$priceString = '';
					if (!empty($item->price))
					{
						if (is_object($item->price))
						{
							$price       = $item->price->final ?? '';
							$priceString = $item->price->final_string ?? '';
						}
						elseif (is_array($item->price))
						{
							$price       = $item->price['final'] ?? '';
							$priceString = $item->price['final_string'] ?? '';
						}
						else
						{
							$price = (string) $item->price;
						}
					}

					$results[] = [
						'id'       => $item->id,
						'title'    => $item->title,
						'code'     => $item->code ?? '',
						'code_exact' => !empty($item->code)
							&& self::normalizeCode($item->code) === $normalizedCode,
						'link'     => $productLink,
						'image'    => $image,
						'price'    => $price,
						'price_string' => $priceString,
						'in_stock' => !empty($item->in_stock)
					];
				}
			}

			return [
				'success' => true,
				'items'   => $results,
				'total'   => count($results)
			];
		}
		catch (\Throwable $e)
		{
			// Подробности остаются в логе: текст исключения может раскрыть
			// внутреннее устройство сайта (запросы, пути), посетителю он не нужен.
			Log::addLogger(['text_file' => 'mod_radicalmart_search.php'], Log::ERROR, ['mod_radicalmart_search']);
			Log::add($e->getMessage(), Log::ERROR, 'mod_radicalmart_search');

			return [
				'success' => false,
				'items'   => [],
				'message' => 'Ошибка при поиске'
			];
		}
	}

	/**
	 * Escapes LIKE wildcards so that "%" and "_" typed by a visitor are searched
	 * literally instead of matching everything.
	 *
	 * @param   string  $value  Raw search text.
	 *
	 * @return  string
	 */
	private static function escapeLike(string $value): string
	{
		return addcslashes($value, '%_\\');
	}

	/**
	 * Finds matching published product IDs by normalized article number.
	 * Spaces and hyphens in a visitor's query are ignored, so PL359 and PL-359
	 * produce the same result.
	 *
	 * @param   string  $search  Visitor search query.
	 * @param   int     $limit   Maximum number of IDs.
	 *
	 * @return  int[]
	 */
	private static function getProductIdsByCode(string $search, int $limit): array
	{
		if ($search === '')
		{
			return [];
		}

		/** @var DatabaseInterface $db */
		$db         = Factory::getContainer()->get(DatabaseInterface::class);
		$column     = "REPLACE(REPLACE(UPPER(" . $db->quoteName('code') . "), '-', ''), ' ', '')";
		$searchCode = '%' . self::escapeLike(mb_strtoupper($search)) . '%';
		$exactCode  = mb_strtoupper($search);
		$query      = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__radicalmart_products'))
			->where($db->quoteName('state') . ' = 1')
			->where($column . ' LIKE :search_code')
			->order('CASE WHEN ' . $column . ' = :exact_code THEN 0 ELSE 1 END')
			->order($db->quoteName('id') . ' DESC')
			->bind(':search_code', $searchCode)
			->bind(':exact_code', $exactCode);

		return array_map('intval', $db->setQuery($query, 0, $limit)->loadColumn());
	}

	/**
	 * Finds published product IDs by title for the autocomplete list.
	 *
	 * @param   string  $search  Visitor search query.
	 * @param   int     $limit   Maximum number of IDs.
	 *
	 * @return  int[]
	 */
	private static function getProductIdsByTitle(string $search, int $limit): array
	{
		$search = trim($search);

		if ($search === '')
		{
			return [];
		}

		/** @var DatabaseInterface $db */
		$db          = Factory::getContainer()->get(DatabaseInterface::class);
		$searchText  = '%' . str_replace(' ', '%', self::escapeLike($search)) . '%';
		$exactTitle  = self::escapeLike($search);
		$query       = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__radicalmart_products'))
			->where($db->quoteName('state') . ' = 1')
			->where($db->quoteName('title') . ' LIKE :search_title')
			->order('CASE WHEN ' . $db->quoteName('title') . ' LIKE :exact_title THEN 0 ELSE 1 END')
			->order($db->quoteName('id') . ' DESC')
			->bind(':search_title', $searchText)
			->bind(':exact_title', $exactTitle);

		return array_map('intval', $db->setQuery($query, 0, $limit)->loadColumn());
	}

	/**
	 * Normalizes an article for a tolerant comparison.
	 *
	 * @param   string  $code  Product code or visitor query.
	 *
	 * @return  string
	 */
	private static function normalizeCode(string $code): string
	{
		return mb_strtoupper((string) preg_replace('/[\s-]+/u', '', $code));
	}

	/**
	 * Loads products through RadicalMart's own model so prices, links, images
	 * and third-party price plugins are prepared exactly as in the catalogue.
	 *
	 * @param   int[]  $ids  Product IDs ordered by relevance.
	 *
	 * @return  object[]
	 *
	 * @throws  \Exception
	 */
	private static function getProductsByIds(array $ids): array
	{
		if (empty($ids))
		{
			return [];
		}

		$app   = Factory::getApplication();
		$model = $app->bootComponent('com_radicalmart')->getMVCFactory()
			->createModel('Products', 'Site', ['ignore_request' => false]);
		// Initialise normal site-model state before replacing only the filters
		// required for article matches.
		$model->getState();
		$model->setState('category.id', 1);
		$model->setState('filter.item_id', $ids);
		$model->setState('filter.item_id.include', true);
		$model->setState('filter.search', '');
		$model->setState('list.limit', count($ids));
		$model->setState('list.start', 0);

		$items  = $model->getItems();
		$indexed = [];
		foreach ($items as $item)
		{
			$indexed[(int) $item->id] = $item;
		}

		return array_values(array_filter(array_map(
			static fn(int $id) => $indexed[$id] ?? null,
			$ids
		)));
	}
}

