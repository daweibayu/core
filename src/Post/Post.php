<?php

/*
 * This file is part of Flarum.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flarum\Post;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Event\GetModelIsPrivate;
use Flarum\Event\ScopeModelVisibility;
use Flarum\Foundation\EventGeneratorTrait;
use Flarum\Post\Event\Deleted;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property int $id
 * @property int $discussion_id
 * @property int $number
 * @property \Carbon\Carbon $time
 * @property int|null $user_id
 * @property string|null $type
 * @property string|null $content
 * @property \Carbon\Carbon|null $edit_time
 * @property int|null $edit_user_id
 * @property \Carbon\Carbon|null $hide_time
 * @property int|null $hide_user_id
 * @property \Flarum\Discussion\Discussion|null $discussion
 * @property User|null $user
 * @property User|null $editUser
 * @property User|null $hideUser
 * @property string $ip_address
 * @property bool $is_private
 */
class Post extends AbstractModel
{
    use EventGeneratorTrait;

    /**
     * {@inheritdoc}
     */
    protected $table = 'posts';

    /**
     * {@inheritdoc}
     */
    protected $dates = ['time', 'edit_time', 'hide_time'];

    /**
     * Casts properties to a specific type.
     *
     * @var array
     */
    protected $casts = [
        'is_private' => 'boolean'
    ];

    /**
     * A map of post types, as specified in the `type` column, to their
     * classes.
     *
     * @var array
     */
    protected static $models = [];

    /**
     * The type of post this is, to be stored in the posts table.
     *
     * Should be overwritten by subclasses with the value that is
     * to be stored in the database, which will then be used for
     * mapping the hydrated model instance to the proper subtype.
     *
     * @var string
     */
    public static $type = '';

    /**
     * {@inheritdoc}
     */
    public static function boot()
    {
        parent::boot();

        // When a post is created, set its type according to the value of the
        // subclass. Also give it an auto-incrementing number within the
        // discussion.
        static::creating(function (Post $post) {
            $post->type = $post::$type;
            $post->number = ++$post->discussion->number_index;
            $post->discussion->save();
        });

        static::saving(function (Post $post) {
            $event = new GetModelIsPrivate($post);

            $post->is_private = static::$dispatcher->until($event) === true;
        });

        static::deleted(function (Post $post) {
            $post->raise(new Deleted($post));
        });

        static::addGlobalScope(new RegisteredTypesScope);
    }

    /**
     * @param Builder $query
     * @param User $actor
     */
    public function scopeWhereVisibleTo(Builder $query, User $actor)
    {
        static::$dispatcher->dispatch(
            new ScopeModelVisibility($query, $actor, 'view')
        );

        // Make sure the post's discussion is visible as well
        $query->whereExists(function ($query) use ($actor) {
            $query->selectRaw('1')
                ->from('discussions')
                ->whereRaw('discussions.id = posts.discussion_id');

            static::$dispatcher->dispatch(
                new ScopeModelVisibility(Discussion::query()->setQuery($query), $actor, 'view')
            );
        });
    }

    /**
     * Determine whether or not this post is visible to the given user.
     *
     * @param User $user
     * @return bool
     */
    public function isVisibleTo(User $user)
    {
        return (bool) $this->newQuery()->whereVisibleTo($user)->find($this->id);
    }

    /**
     * Define the relationship with the post's discussion.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function discussion()
    {
        return $this->belongsTo('Flarum\Discussion\Discussion', 'discussion_id');
    }

    /**
     * Define the relationship with the post's author.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo('Flarum\User\User', 'user_id');
    }

    /**
     * Define the relationship with the user who edited the post.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function editUser()
    {
        return $this->belongsTo('Flarum\User\User', 'edit_user_id');
    }

    /**
     * Define the relationship with the user who hid the post.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function hideUser()
    {
        return $this->belongsTo('Flarum\User\User', 'hide_user_id');
    }

    /**
     * Get all posts, regardless of their type, by removing the
     * `RegisteredTypesScope` global scope constraints applied on this model.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeAllTypes(Builder $query)
    {
        return $query->withoutGlobalScopes();
    }

    /**
     * Create a new model instance according to the post's type.
     *
     * @param array $attributes
     * @param string|null $connection
     * @return static|object
     */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;

        if (! empty($attributes['type'])
            && isset(static::$models[$attributes['type']])
            && class_exists($class = static::$models[$attributes['type']])
        ) {
            /** @var Post $instance */
            $instance = new $class;
            $instance->exists = true;
            $instance->setRawAttributes($attributes, true);
            $instance->setConnection($connection ?: $this->connection);

            return $instance;
        }

        return parent::newFromBuilder($attributes, $connection);
    }

    /**
     * Get the type-to-model map.
     *
     * @return array
     */
    public static function getModels()
    {
        return static::$models;
    }

    /**
     * Set the model for the given post type.
     *
     * @param string $type The post type.
     * @param string $model The class name of the model for that type.
     * @return void
     */
    public static function setModel($type, $model)
    {
        static::$models[$type] = $model;
    }
}
