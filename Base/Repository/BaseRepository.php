<?php
namespace Modules\Base\Repository;

use Intervention\Image\Facades\Image;
use App\Infrastructure\Services\Utils\ArrayToDot;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Input;

class BaseRepository implements BaseRepositoryInterface{

    protected $errorMessage = null;
    protected $model;
    protected $withParams = [];
    public $paginate = false;
    protected $limit = 10;
    protected $where = [
        'get' => []
    ];
    protected $currentQuery = null;
    protected $rules = [];
    protected $rulesEdit = [];
    protected $validator;
    protected $storedObject;
    protected $fields= ['*'];
    public $orderByParams = [
        ['id', "DESC"]
    ];
    protected $linked = [];
    protected $path = null;
    public $imageConfig = [];
    public $imageSizes = [
        [
            'width' => 280,
            'height' => 190,
            'prefix' => "th"
        ],
        [
            'width' => 320,
            'height' => 280,
            'prefix' => "sm"
        ],
        [
            'width' => 480,
            'height' => 320,
            'prefix' => "md"
        ],
        [
            'width' => null,
            'height' => 480,
            'prefix' => "lg"
        ],
        [
            'width' => null,
            'height' => 720,
            'prefix' => "lghd"
        ],
        [
            'width' => null,
            'height' => 1080,
            'prefix' => "hd"
        ]
    ];

    public $imageCropSizes = [
        "width" => 0,
        "height" => 0
    ];
    public $imageCrop = false;

    public function model($model)
    {
        $this->model = $model;
        return $this;
    }

    public function rules($rules){
        $this->rules  = $rules;
        return $this;
    }

    public function fields($fields)
    {
        $this->fields = $fields;
        return $this;
    }

    public function getErrors()
    {
        return (isset($this->validator)?$this->validator->messages():[]);
    }

    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    public function with($params){
        $this->withParams = $params;
        return $this;
    }

    public function paginate($state = true, $limit=10){
        $this->paginate = $state;
        $this->limit = $limit;
        return $this;
    }


    public function where($data, $type="get"){
        if(array_key_exists($type, $this->where))
        {
            $this->where[$type][] = $data;
        }else{
            $this->where[$type] = [$data];
        }
        return $this;
    }

    public function startQuery()
    {
         $this->currentQuery = $this->model->select($this->fields)
            ->with($this->withParams);
            return $this;
    }


    public function prepareQuery(){
        $tableName = $this->model->getTable();
        if($this->currentQuery == null)
        {
            $this->startQuery();
        }

        foreach ($this->orderByParams as $each) {
            $this->currentQuery
                    ->orderBy($each[0], $each[1]);
        }
//        ->orderBy($this->orderByParams[0], $this->orderByParams[1])
        foreach($this->where['get'] as $key => $value)
        {
            $this->currentQuery->where($value[0], $value[1], $value[2]);
        }

        

        return $this->currentQuery;
    }

    public function destroy($id) {
        $obj = $this->model->find($id);
        if(empty($obj))
            return false;
        $obj->delete();
        return true;
    }

    public function get($id) {
        return $this->startQuery()->prepareQuery()->where("id", "=", $id)->first();
    }

    public function getAll($request) {
        $this->startQuery();
        $data = [];
        $limit = $request->get("limit", -1);
        $requestData = $request->all();
         if(!empty($requestData))
        {
            if(isset($requestData['fields']))
            {
                $fields = $requestData['fields'];
                $fieldsArray = explode(",",$fields);
                foreach ($fieldsArray as $eachField) {
                    $fieldDataArray = explode(":", $eachField);
                    $nestedLoop = explode(".", $fieldDataArray[0]);

                    // dd($nestedLoop);
                    if(count($nestedLoop)> 1)
                    {
                        $tempKey = $nestedLoop[count($nestedLoop)-1];
                        unset($nestedLoop[count($nestedLoop)-1]);
                        $tempWithString = implode(".",$nestedLoop);
                        $tempValue = $fieldDataArray[1];

                        $this->currentQuery->whereHas($tempWithString, function($q)
                                use ($tempKey, $tempValue){
                            $q->where($tempKey, "LIKE", '%'.$tempValue.'%');
                        });
                    }else{
                        if(count($fieldDataArray) == 2)
                        {
                            $this->where([$fieldDataArray[0], "LIKE", '%'.$fieldDataArray[1].'%']);
                        }
                    }
                }
            }

            if(isset($requestData['sort_by']))
            {
                $sort = $requestData['sort_by'];
                $sortArray = explode(",", $sort);
                if(!empty($sortArray))
                {
                    $this->orderByParams = [];
                    foreach ($sortArray as $eachSort) {
                        $sortTypeArray = explode(":", $eachSort);
                        $type = "ASC";
                        $field =$sortTypeArray[0];
                        if(count($sortTypeArray) > 1)
                        {
                            $type = $sortTypeArray[1];
                        }
                        $this->orderByParams[] = [$field, $type];
                    }
                }
            }
        }


        if($limit == -1){
            if($this->paginate)
            {
                $data = $this->prepareQuery()->paginate(10);

            }else{

                $data = $this->prepareQuery()
//                        ->whereHas("bankaccounts", function($q){
//                            $q->where("address_id", "=", 1);
//                        })
                        ->get();
            }
        }else{
            $data = $this->prepareQuery()->take($limit)->get();
        }

        return $data;
    }

    public function validateInput($data)
    {
        $this->validator = \Validator::make($data, $this->rules);
        if($this->validator->fails())
        {
            return false;
        }
        return true;
    }

    public function store($data) {
//        htmlspecialchars
        $tableName = $this->model->getTable();

        if(key_exists("password", $data))
        {
            $data['password'] = \Hash::make($data['password']);
        }

        foreach ($this->linked as $key => $value) {
            if(key_exists($key, $data)){
                unset($this->rules[$key."_id"]);
                $this->rules = ArrayToDot::merge($this->rules, $value['model']::$rules, $key);
            }
        }

//        $this->validator = \Validator::make($data, $this->rules);
//        if($this->validator->fails())
//        {
//            return false;
//        }
        if(!$this->validateInput($data))
        {
            return false;
        }

        foreach ($this->linked as $key => $value) {
            $tempRepository = new $value['repo'](new $value['model']);
            $tempRepository->store($data[$key]);
            unset($data[$key]);
            $data[$key."_id"] = $tempRepository->getStoredObject()->id;
        }

        foreach($data as $key => $value)
        {
            if(is_object($value) || is_array($value))
            {
                $data[$key] = json_encode($value);
            }
        }

        if(array_key_exists("user_id", $this->rules)
                && !isset($data['user_id']))
        {
//            var_dump( \Auth::check());exit;

            $data['user_id'] = \Auth::check() ? \Auth::id():1;
        }

        if(\Schema::hasColumn($tableName, 'created_by') && \Auth::check()){
               $data['created_by']  = \Auth::id();
        }

        if(\Schema::hasColumn($tableName, 'company_id') && \Auth::check())
        {
            $data['company_id'] = \Auth::user()->company_id;
        }

//        var_dump($data);exit;
        $this->storedObject = new $this->model($data);
        $this->storedObject->save();
        return true;
    }

    public function getStoredObject()
    {
        return $this->storedObject;
    }

    public function update($id, $data) {
        $tableName = $this->model->getTable();
        if(key_exists("password", $data))
        {
            $data['password'] = \Hash::make($data['password']);
        }
        $this->storedObject = $this->model->find($id);
        foreach($this->imageConfig as $key => $value)
        {
            if($value['type'] ==  'morph' && $value['multiple'] == false && array_key_exists($value['variable'], $data)){
                $temp = array_pull($data, $value['variable']);

                $name = strtolower(str_replace('\\', '_', get_class($this->storedObject)) . '_'.$value['image_type'].'_h' .
                        str_pad($this->storedObject->id, 6, '0', STR_PAD_LEFT))
                        .date("mYdHis");

                $file = $this->uploadAndGetInformation($temp, [
                        'name'			=>	$name,
                        'caption'		=>	$value['image_type'].' image',
                        'destination'           =>	 'uploads',
                        'type'			=>	$value['image_type']
                ]);
                $image = new $value['morphTo']($file);
                $this->storedObject->image()->save($image);
                $this->storedObject = $this->get($this->storedObject->id);
                return true;
            }else if($value['type'] == 'morph' && $value['multiple'] == true && array_key_exists($value['variable'], $data))
            {
                $temp = array_pull($data, $value['variable']);

                foreach ($temp as $key => $eachImage) {

                    $now = \DateTime::createFromFormat('U.u', microtime(true));
                    $date = $now->format("mYdHis");
                    $name = strtolower(str_replace('\\', '_', get_class($this->storedObject))
                            . '_'.$value['image_type'].'_h' .
                        str_pad($this->storedObject->id, 6, '0', STR_PAD_LEFT))
                        .$date.$key;
//                    dd($eachImage);
                    $file = $this->uploadAndGetInformation($eachImage, [
                            'name'			=>	$name,
                            'caption'		=>	$value['image_type'].' image',
                            'destination'           =>	 'uploads',
                            'type'			=>	$value['image_type']
                    ]);


                    $image = new $value['morphTo']($file);
                    $this->storedObject->image()->save($image);

                }

                $this->storedObject = $this->get($this->storedObject->id);
                return true;
            }else if($value['type'] == 'table' && $value['multiple'] == false && array_key_exists($value['variable'], $data))
            {

                $temp = array_pull($data, $value['variable']);

                $name = strtolower(str_replace('\\', '_', get_class($this->storedObject)) . '_'.$value['image_type'].'_h' .
                        str_pad($this->storedObject->id, 6, '0', STR_PAD_LEFT))
                        .date("mYdHis");

                $file = $this->uploadAndGetInformation($temp, [
                        'name'			=>	$name,
                        'caption'		=>	$value['image_type'].' image',
                        'destination'           =>	 'uploads',
                        'type'			=>	$value['image_type']
                ]);

                $this->storedObject->icon = json_encode($file);
                $this->storedObject->save();
                $this->storedObject = $this->get($this->storedObject->id);
                return true;
            }
        }

        $this->rules = array_merge($this->rules, $this->rulesEdit);

        foreach ($this->linked as $key => $value) {
            if(key_exists($key, $data)){
                unset($this->rules[$key."_id"]);
                $this->rules = ArrayToDot::merge($this->rules, $value['model']::$rules, $key);
            }
        }
//
//        $this->storedObject = $this->model->find($id);
//        $rules = [];
//        foreach ($data as $key => $value) {
//
//            if(is_object($value) || is_array($value))
//            {
//                $data[$key] = json_encode($value);
//            }else{
//                $data[$key] = strip_tags($value, '<div><p><a><br><span>');
//            }
//
//            $this->storedObject->$key = $data[$key];
//            if(array_key_exists($key, $this->rules))
//            {
//                $rules[$key] = $this->rules[$key];
//            }
//        }

        $this->validator = \Validator::make($data, $this->rules);
        if($this->validator->fails())
        {
            return false;
        }






        foreach ($this->linked as $key => $value) {
            if(isset($data[$key])){
                $tempRepository = new $value['repo'](new $value['model']);
                if(isset($data[$key]['id'])){
                    $tempRepository->update($data[$key]['id'], $data[$key]);
                }else{
                    $tempRepository->store( $data[$key]);
                    $data[$key."_id"] = $tempRepository->getStoredObject()->id;
                }
                unset($data[$key]);
            }
//            $data[$key."_id"] = $tempRepository->getStoredObject()->id;
        }


        foreach ($data as $key => $value) {

            if(is_object($value) || is_array($value))
            {
                $data[$key] = json_encode($value);
            }else{
                $data[$key] = strip_tags($value, '<div><p><a><br><span>');
            }

            if(\Schema::hasColumn($tableName, $key) &&
                    $this->storedObject->$key != $data[$key]){
                $this->storedObject->$key = $data[$key];
            }
            if(array_key_exists($key, $this->rules))
            {
                $rules[$key] = $this->rules[$key];
            }
        }
        $this->storedObject->save();



        $this->storedObject = $this->get($this->storedObject->id);
        return true;
    }


    public function __log($type, $data, $syncState = "no", $syncID = "")
    {
        return null;
    }

    public function moveFile($file)
    {

    }

    public function moveImage($file)
    {
        $filename = $file->getClientOriginalName();
        $mime = $file->getMimeType();

        $type = $file->getClientOriginalExtension();
        $fileName = preg_replace('/[^a-z0-9]/i', '_', $filename)."_".substr(md5(date("YmdHis")), 0, 10).".".$type;
        $filePath = storage_path().DIRECTORY_SEPARATOR."files";
        $file->move($filePath, $fileName);
        Image::make($filePath.DIRECTORY_SEPARATOR.$fileName
                )->resize(320, null)->save($filePath.DIRECTORY_SEPARATOR.'thumbnails'.DIRECTORY_SEPARATOR.$fileName);

         return  [
            'key' => md5(date("YmdHis")),
            'file' => $fileName,
            'path' => 'files',
            'mime' => $mime
        ];
    }

    public function setDefaultPath($path = null)
    {
        if(is_null($path))
        {
            $this->path = storage_path().DIRECTORY_SEPARATOR.'app';
        }else{
            $this->path = $path;
        }
    }

    /**
     *
     * @param UploadedFile $file
     * @param array [
     *               'name'		=>	$name,
     *               'caption'		=>	$cover_photo['caption'],
     *               'destination'	=>	'uploads',
     *               'type'		=>	'cover'
     *       ] $option
     * @return boolean|array
     */
    public function uploadAndGetInformation(UploadedFile $file, array $option = array()){
            if(is_null($this->path)){
                $this->setDefaultPath();
            }

            $name = $option['name'] . '.' . $file->getClientOriginalExtension();

            if (!$file->isValid()) return false;
            try {
                    $file->move($this->path.DIRECTORY_SEPARATOR.$option['destination'], $name);

                    $uploadedFilePath = $this->path.DIRECTORY_SEPARATOR.$option['destination'].DIRECTORY_SEPARATOR.$name;
                    $cropedFilePath = $this->path.DIRECTORY_SEPARATOR.$option['destination'].DIRECTORY_SEPARATOR."croped".DIRECTORY_SEPARATOR.$name;
                    $filePath = $uploadedFilePath;
                    if($this->imageCrop)
                    {
                        Image::make($uploadedFilePath)->sharpen(4)
                                ->fit($this->imageCropSizes['width'], $this->imageCropSizes['height'])
                                ->save($cropedFilePath);
                        $filePath = $cropedFilePath;
                    }

                Image::make(
                        $filePath
                )->sharpen(4)->resize(480, 320, function($constraint){
                    $constraint->aspectRatio();
                })->save($this->path.DIRECTORY_SEPARATOR.$option['destination'].DIRECTORY_SEPARATOR.'th_'.$name);


                foreach ($this->imageSizes as $size) {
                    Image::make(
                            $filePath
                    )->sharpen(4)->resize($size['width'], $size['height'], function($constraint){
                        $constraint->aspectRatio();
                    })->save($this->path.DIRECTORY_SEPARATOR.$option['destination'].DIRECTORY_SEPARATOR.$size['prefix'].DIRECTORY_SEPARATOR.$name);
                }

                    $file = [ 'name' => $name, 'caption' => $option['caption'],
                        'file' => $option['destination'] . '/' . $name,
                        'type' => (isset($option['type']) ? $option['type'] : null) ];
                    return $file;
            } catch (Exception $e) {

                    return false;
            }
    }

}
