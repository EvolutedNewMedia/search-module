<?php

namespace Anomaly\SearchModule;

use Anomaly\SearchModule\Item\Contract\ItemRepositoryInterface;
use Anomaly\SearchModule\Search\SearchCriteria;
use Anomaly\Streams\Platform\Addon\Plugin\Plugin;
use Anomaly\Streams\Platform\Entry\Contract\EntryInterface;

/**
 * Class SearchModulePlugin
 *
 * @link   http://pyrocms.com/
 * @author PyroCMS, Inc. <support@pyrocms.com>
 * @author Ryan Thompson <ryan@pyrocms.com>
 */
class SearchModulePlugin extends Plugin
{

    /**
     * Get the functions.
     *
     * @return array
     */
    public function getFunctions()
    {
        return [
            new \Twig_SimpleFunction(
                'search',
                function ($search, $options = []) {

                    /* @var ItemRepositoryInterface $repository */
                    $repository = app(ItemRepositoryInterface::class);

                    /* @var EntryInterface $model */
                    $query = $repository->newQuery();
                    $model = $repository->getModel();

                    /**
                     * Restrict the query to the active locale.
                     */
                    $query->where('locale', array_get($options, 'locale', config('app.locale')));

                    $query->where(
                        function () use ($query, $search, $options) {

                            $threshold = array_get($options, 'threshold', 3);

                            /**
                             * Remove symbols used by MySQL.
                             */
                            $reservedSymbols = ['-', '+', '<', '>', '@', '(', ')', '~'];
                            $term            = str_replace($reservedSymbols, '', $search);

                            $words = explode(' ', $term);

                            foreach ($words as $key => $word) {

                                /*
                                 * applying + operator (required word) only big words
                                 * because smaller ones are not indexed by mysql
                                 */
                                if (strlen($word) >= 3) {
                                    $words[$key] = '+' . $word . '*';
                                }
                            }

                            /**
                             * Match the primary index fields.
                             * Title and description should trump
                             * anything else that get's matched.
                             */
                            $match = app('db')->raw(
                                'MATCH (title,description) AGAINST ("' . implode(' ', $words) . '")'
                            );

                            //$query->addSelect($match . ' AS _score');
                            $query->where($match, '>=', $threshold);
                            $query->orderBy($match, 'DESC');

                            /**
                             * Match in the searchable data
                             * if possible. Expect lower scores.
                             */
                            $match = app('db')->raw(
                                'MATCH (searchable) AGAINST ("' . implode(' ', $words) . '")'
                            );

                            //$query->addSelect($match . ' AS _score');
                            $query->orWhere($match, '>=', $threshold);
                            $query->orderBy($match, 'DESC');

                            /**
                             * Match multiple words against
                             * the primary fields as well.
                             */
                            if (count($words) > 1) {
                                foreach ($words as $k => $word) {

                                    $match = app('db')->raw('MATCH (title,description) AGAINST ("' . $word . '")');

                                    //$query->addSelect($match . ' AS _score' . ($k + 1));
                                    $query->orWhere($match, '>=', $threshold);
                                    $query->orderBy($match, 'DESC');
                                }
                            }
                        }
                    );

                    return new SearchCriteria($query, $model->getStream(), 'get');
                }
            ),
        ];
    }
}
