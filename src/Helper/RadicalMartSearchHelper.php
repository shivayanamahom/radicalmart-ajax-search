<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_radicalmart_search
 *
 * @copyright   (C) 2025
 * @license     GNU General Public License version 2 or later
 */

namespace Joomla\Module\RadicalMartSearch\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
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
			$app = Factory::getApplication();
			
			// Fallback: пытаемся использовать текущую категорию, если мы на странице каталога
			if ($app->input->get('option') === 'com_radicalmart'
				&& $app->input->get('view') === 'category'
				&& $app->input->getInt('id'))
			{
				$link = RouteHelper::getCategoryViewRoute($app->input->getInt('id'));
			}
			else
			{
				// Используем корневую категорию
				$link = RouteHelper::getCategoryViewRoute(1);
			}
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
	public static function getAjax()
	{
		$app   = Factory::getApplication();
		$query = $app->input->getString('query', '');

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

			// Используем модель Products с правильным контекстом.
			// 'filter.search' - штатный ключ текстового поиска RadicalMart (title/introtext/
			// fulltext/search_text), расширенный полем 'code' (артикул) через
			// plg_radicalmart_searchcode (onRadicalMartAddFilterSearchListQuery).
			$app->input->set('filter', ['search' => $query]);
			
			$model = $app->bootComponent('com_radicalmart')->getMVCFactory()
				->createModel('Products', 'Site', ['ignore_request' => false]);
			
			// The RadicalMart text search also checks descriptions, fields and
			// search_text. Load a wider window here and apply the autocomplete's
			// stricter title matching below, otherwise an irrelevant match can
			// occupy one of the first ten suggestions.
			$model->setState('list.limit', 50);
			$model->setState('list.start', 0);
			
			// Get products
			$items = $model->getItems();

			$results = [];
			$seenTitles = [];
			if (!empty($items))
			{
				foreach ($items as $item)
				{
					// Autocomplete should answer what is typed in the product name,
					// not a coincidental fragment in a description or custom field.
					if (!static::titleMatchesSearch($item->title ?? '', $query)
						&& !static::codeMatchesSearch($item->code ?? '', $query))
					{
						continue;
					}

					// Products and their meta/variant records can have the same
					// title. Showing the same suggestion twice is not useful.
					$titleKey = static::normalizeSearchText((string) ($item->title ?? ''));
					if ($titleKey === '' || isset($seenTitles[$titleKey]))
					{
						continue;
					}
					$seenTitles[$titleKey] = true;

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

					// Get price
					$price = '';
					if (!empty($item->price))
					{
						if (is_object($item->price) && isset($item->price->final))
						{
							$price = $item->price->final;
						}
						elseif (is_array($item->price) && isset($item->price['final']))
						{
							$price = $item->price['final'];
						}
						else
						{
							$price = (string) $item->price;
						}
					}

					$results[] = [
						'id'       => $item->id,
						'title'    => $item->title,
						'link'     => $productLink,
						'image'    => $image,
						'price'    => $price,
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
		catch (\Exception $e)
		{
			return [
				'success' => false,
				'items'   => [],
				'message' => 'Ошибка при поиске: ' . $e->getMessage()
			];
		}
	}

	/**
	 * Check all query words against the product title.
	 *
	 * @param   string  $title  Product title.
	 * @param   string  $query  User input.
	 *
	 * @return  bool
	 */
	private static function titleMatchesSearch(string $title, string $query): bool
	{
		$title = static::normalizeSearchText($title);
		$terms = static::searchTerms($query);

		if ($title === '' || empty($terms))
		{
			return false;
		}

		foreach ($terms as $term)
		{
			if (mb_strpos($title, $term) === false)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Keep article-number lookup working without allowing arbitrary metadata
	 * matches into the title-oriented autocomplete.
	 *
	 * @param   string  $code   Product code.
	 * @param   string  $query  User input.
	 *
	 * @return  bool
	 */
	private static function codeMatchesSearch(string $code, string $query): bool
	{
		$code = static::normalizeSearchText($code);
		$query = static::normalizeSearchText($query);

		return $code !== '' && $query !== '' && mb_strpos($code, $query) !== false;
	}

	/**
	 * Normalize text for case-insensitive Cyrillic/Latin matching.
	 *
	 * @param   string  $value  Text to normalize.
	 *
	 * @return  string
	 */
	private static function normalizeSearchText(string $value): string
	{
		$value = mb_strtolower(trim($value), 'UTF-8');
		$value = str_replace('ё', 'е', $value);
		$value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

		return trim($value);
	}

	/**
	 * Split user input into meaningful search terms.
	 *
	 * @param   string  $query  User input.
	 *
	 * @return  string[]
	 */
	private static function searchTerms(string $query): array
	{
		$normalized = static::normalizeSearchText($query);

		return array_values(array_filter(explode(' ', $normalized), static function (string $term): bool {
			return mb_strlen($term, 'UTF-8') >= 2;
		}));
	}
}

