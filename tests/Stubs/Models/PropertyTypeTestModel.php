<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models;

/**
 * A test model for property type analysis
 */
class PropertyTypeTestModel
{
    /**
     * The model's ID
     * 
     * @var int
     * @required
     */
    public int $id;
    
    /**
     * The model's name
     * 
     * @var string
     */
    public string $name;
    
    /**
     * Email address for the model
     * 
     * @var string
     */
    public string $email;
    
    /**
     * The model's creation date
     * 
     * @var string
     */
    public string $createdAt;
    
    /**
     * Status of the model
     * 
     * @var string
     * @enum {active, inactive, pending}
     */
    public string $status;
    
    /**
     * Optional description
     */
    public ?string $description = null;
    
    /**
     * The model's URL
     */
    public string $url;
    
    /**
     * A floating point value
     */
    public float $price;
    
    /**
     * Count of items
     */
    public int $count;
}
