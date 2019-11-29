<?php

namespace DigitalCreative\Filepond;

use Exception;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Http\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Controllers\ResourceShowController;
use Laravel\Nova\Http\Requests\NovaRequest;
use Symfony\Component\Mime\MimeTypes;

class Filepond extends Field
{
    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'filepond';

    /**
     * On delete callback.
     *
     * @var callable
     */
    public $onDeleteCallback;

    /**
     * On store callback.
     *
     * @var callable
     */
    public $storeAsCallback;

    /**
     * @var string
     */
    private $disk = 'public';

    /**
     * @var bool
     */
    private $multiple = false;

    /**
     * @var null
     */
    private $directory = null;

    /**
     * Create a new field.
     *
     * @param string $name
     * @param string|callable|null $attribute
     * @param callable|null $resolveCallback
     *
     * @return void
     */
    public function __construct($name, $attribute = null, callable $resolveCallback = null)
    {

        parent::__construct($name, $attribute, $resolveCallback);

        /**
         * Temporarily as it currently only supports image and it`s not very pretty yet
         */
        $this->showOnIndex = false;

    }

    public function disable(): self
    {
        return $this->withMeta([ 'disabled' => true ]);
    }

    public function fullWidth(): self
    {
        return $this->withMeta([ 'fullWidth' => true ]);
    }

    public function columns(int $columns): self
    {
        return $this->withMeta([ 'columns' => $columns ]);
    }

    public function limit(int $amount): self
    {
        return $this->withMeta([ 'limit' => $amount ]);
    }

    public function mimesTypes($mimesTypes): self
    {
        $mimesTypes = is_array($mimesTypes) ? $mimesTypes : func_get_args();

        return $this->withMeta(
            [ 'mimesTypes' => array_merge($this->meta[ 'mimesTypes' ] ?? [], $mimesTypes) ]
        );
    }

    public function maxHeight(string $heightWithUnit): self
    {
        return $this->withMeta([ 'maxHeight' => $heightWithUnit ]);
    }

    public static function guessMimeType(string $extension): ?string
    {
        return MimeTypes::getDefault()->getMimeTypes($extension)[ 0 ] ?? null;
    }

    public function single(): self
    {
        $this->multiple = false;

        return $this;
    }

    public function multiple(): self
    {
        $this->multiple = true;

        return $this;
    }

    public function image(): self
    {
        return $this->mimesTypes('image/jpeg', 'image/png', 'image/svg+xml');
    }

    public function video(): self
    {
        return $this->mimesTypes('video/mp4', 'video/webm', 'video/ogg');
    }

    public function audio(): self
    {
        return $this->mimesTypes('audio/wav', 'audio/mp3', 'audio/ogg', 'audio/webm');
    }

    public function withDoka(array $options = []): self
    {
        return $this->withMeta([
            'dokaEnabled' => true,
            'dokaOptions' => array_merge(config('nova-filepond.doka.options', []), $options)
        ]);
    }

    public function labels(array $labels): self
    {
        return $this->withMeta([ 'labels' => $labels ]);
    }

    /**
     * Disable Doka, you dont need to call this method if you haven't globally enabled it from the config file
     *
     * @return $this
     */
    public function withoutDoka(): self
    {
        return $this->withMeta([ 'dokaEnabled' => false ]);
    }

    /**
     * @param string $disk
     * @param string|null $directory
     *
     * @return $this
     */
    public function disk(string $disk, string $directory = null)
    {
        $this->disk = $disk;
        $this->directory = $directory;

        return $this;
    }

    /**
     * Callback when image is being deleted.
     *
     * @param callable $callback
     * @return $this
     */
    public function onDelete(callable $callback) {

        $this->onDeleteCallback = $callback;

        return $this;
    }

    /**
     * Callback when image(s) is being stored.
     *
     * @param callable $callback
     * @return $this
     */
    public function storeAs(callable $callback) {

        $this->storeAsCallback = $callback;

        return $this;

    }

    public function updateRules($rules)
    {

        if ($this->shouldApplyRules()) {

            $this->updateRules = ($rules instanceof Rule || is_string($rules)) ? func_get_args() : $rules;

        }

        return $this;

    }

    public function creationRules($rules)
    {

        if ($this->shouldApplyRules()) {

            $this->creationRules = ($rules instanceof Rule || is_string($rules)) ? func_get_args() : $rules;

        }

        return $this;

    }

    public function rules($rules)
    {

        if ($this->shouldApplyRules()) {

            $this->rules = ($rules instanceof Rule || is_string($rules)) ? func_get_args() : $rules;

        }

        return $this;

    }

    private function shouldApplyRules(): bool
    {
        return request()->routeIs('nova.filepond.process') || request()->input($this->attribute) === null;
    }

    /**
     * Check if given string is enrypted.
     *
     * @param $encrypted
     * @return bool
     */
    public static function isEncryptedString($encrypted) {
        if(!is_string($encrypted)) {
            return false;
        }

        switch(config('app.cipher')) {
            case 'AES-256-CBC':
                return strlen(str_replace(['/','_','-'], '', explode('.', $encrypted)[0])) > 66;
                break;
            // @todo: any other ciphers used with laravel
        }
    }

    /**
     * Hydrate the given attribute on the model based on the incoming request.
     *
     * @param NovaRequest $request
     * @param string $requestAttribute
     * @param object $model
     * @param string $attribute
     *
     * @return mixed
     * @throws Exception
     */
    protected function fillAttributeFromRequest(NovaRequest $request, $requestAttribute, $model, $attribute) {

        $encryptedServerId = $request->input($requestAttribute);
        // current images are images assigned to the attribute of this model.
        $currentFiles = collect($model->{$requestAttribute});

        $hasUploadedFiles = $request->input($requestAttribute) !== null;
        $modelHasFiles = $model->{$requestAttribute} !== null;

        // single image
        if ($this->multiple === false) {

            $uploadedFile = is_null($encryptedServerId) ? null : static::getPathFromServerId($encryptedServerId);
            $uploadedFileIsTmp = Str::startsWith($uploadedFile, '/tmp/');

            /**
             * null when all images are removed
             */
            if ($hasUploadedFiles === false) {
                // reset attribute on model to empty if no image was passed.
                if ($modelHasFiles === true) {
                    $this->removeFiles($currentFiles, $model, $attribute);
                }
                $model->setAttribute($requestAttribute, null);
                return;
            }

            // file is newly uploaded and model did not had a previous file
            if($uploadedFileIsTmp === true && $model->{$requestAttribute} === $uploadedFile) {
                // delete files
                $this->removeFiles($currentFiles);
                // reset to go into next if.
                $model->setAttribute($requestAttribute, null);
            }

            // file is newly uploaded and model has a previous file attached
            if ($uploadedFileIsTmp === true && $model->{$requestAttribute} !== $uploadedFile) {
                /**
                 * Do not fail if image doesn't exist. We only keep current value of given `$model->{$attribute}` and set
                 * it with it's original value.
                 */
                try {
                    $file = new File($uploadedFile);
                    $savedFile = $this->moveFile($file);
                    $model->setAttribute($attribute, $savedFile);
                } catch (\Exception $e) {
                    $model->setAttribute($attribute, $uploadedFile);
                    return;
                }

                return;
            }

            // save attribute with the decrypted correct value.
            $model->setAttribute($requestAttribute, $uploadedFile);
            return;

        }

        /**
         * If it`s a multiple files request
         */
        $files = collect(explode(',', $request->input($requestAttribute)))->map(function ($file) {
            return static::getPathFromServerId($file);
        });

        $toKeep = $files->intersect($currentFiles); // files that exist on the request and on the model
        $toAppend = $files->diff($currentFiles); // files that exist only on the request
        $toDelete = $currentFiles->diff($files); // files that doest exist on the request but exist on the model

        $this->removeFiles($toDelete);

        foreach ($toAppend as $uploadedFile) {
            try {
                $file = new File($uploadedFile);
                $toKeep->push($this->moveFile($file));
            }
            catch(\Exception $e) {
                // skip
            }
        }

        $model->setAttribute($attribute, $toKeep->values());

    }

    private function trimSlashes(string $path): string
    {
        return trim(rtrim($path, '/'), '/');
    }

    private function moveFile(File $file): string
    {

        $name = $this->storeAsCallback ? call_user_func($this->storeAsCallback, $file) : $file->getBasename();
        $fullPath = $this->trimSlashes($this->directory ?? '') . '/' . $this->trimSlashes($name);

        $response = Storage::disk($this->disk)->put($fullPath, file_get_contents($file->getRealPath()));

        if ($response) {

            return $this->trimSlashes($fullPath);

        }

        throw new Exception(__('Failed to upload file.'));

    }

    /**
     * @param Collection $files
     */
    private function removeFiles(Collection $files, $model, $attribute): void {
        foreach ($files as $image) {
            Storage::disk($this->disk)->delete($image);
            if ($this->onDeleteCallback) {
                call_user_func($this->onDeleteCallback, $model, $attribute, $image, $this->disk);
            }
        }
    }

    /**
     * @deprecated
     * @param Collection $images
     */
    private function removeImages(Collection $images): void {
        $this->removeFiles($images);
    }

    /**
     * Resolve the given attribute from the given resource.
     *
     * @param mixed $resource
     * @param string $attribute
     *
     * @return mixed
     */
    protected function resolveAttribute($resource, $attribute): Collection
    {

        $value = parent::resolveAttribute($resource, $attribute);

        return collect($value)->map(function ($value) {
            return [
                'source' => $this->getServerIdFromPath($value),
                'options' => [
                    'type' => 'local'
                ]
            ];
        });

    }

    private function getThumbnails(): Collection
    {

        if (blank($this->value)) {

            return collect();

        }

        return $this->value->map(function ($value) {

            return Storage::disk($this->disk)->url(self::getPathFromServerId($value[ 'source' ]));

        });

    }

    /**
     * Converts the given path into a filepond server id
     *
     * @param string $path
     *
     * @return string
     */
    public static function getServerIdFromPath(string $path): string
    {
        return encrypt($path);
    }

    /**
     * Converts the given filepond server id into a path
     *
     * @param string $serverId
     *
     * @return string
     */
    public static function getPathFromServerId($serverId): string {
        return static::isEncryptedString($serverId) ? decrypt($serverId) : $serverId;
    }

    private function getLabels(): Collection
    {

        $labels = collect(config('nova-filepond.labels', []))
            ->merge($this->meta[ 'labels' ] ?? [])
            ->mapWithKeys(function ($label, $key) {
                return [ "label" . Str::title($key) => trans($label) ];
            });

        return $labels;

    }

    /**
     * Prepare the field for JSON serialization.
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return array_merge([
            'disk' => $this->disk,
            'multiple' => $this->multiple,
            'disabled' => request()->route()->controller instanceof ResourceShowController,
            'thumbnails' => $this->getThumbnails(),
            'columns' => 1,
            'fullWidth' => false,
            'maxHeight' => 'auto',
            'limit' => null,
            'dokaOptions' => config('nova-filepond.doka.options'),
            'dokaEnabled' => config('nova-filepond.doka.enabled'),
            'labels' => $this->getLabels(),
        ], $this->meta(), parent::jsonSerialize());
    }

}
