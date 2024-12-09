<?php

use Gt\CssXPath\Translator;

// require_once( 'CssXPath/Translator.php' );
// https://github.com/PhpGt/CssXPath

class PTDomDocument extends DomDocument {

    const MB_ENCODE_NUMERICENTITY_MAP = [0x80, 0x10FFFF, 0, 0x1FFFFF];
    private $xpath = null;
    public  $text_encoding = 'utf-8';

    public function __construct ( $version = '1.0', $encoding = 'utf-8' ) {
        $this->text_encoding = $encoding;
        parent::__construct( $version, $encoding );
        $this->registerNodeClass( 'DOMElement', 'PTDOMElement' );
    }

    function loadHTML ( $source, $options = 0 ) {
        libxml_use_internal_errors( true );
        if ( preg_match( '/^\xEF\xBB\xBF/', $source ) ) {
            // BOM
            $source = preg_replace( '/^\xEF\xBB\xBF/', '', $source );
        }
        $source = mb_encode_numericentity( $source, self::MB_ENCODE_NUMERICENTITY_MAP, $this->text_encoding );
        if ( $options ) {
            $res = parent::loadHTML( $source, $options );
        } else {
            $res = parent::loadHTML( $source, LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_COMPACT );
        }
        libxml_clear_errors();
        $this->xpath = new DOMXpath( $this );
        return $res;
    }

    function loadHTMLFile ( $filename, $options = 0 ) {
        $source = @file_get_contents( $filename );
        if ( $source === false ) {
            return false;
        }
        return $this->loadHTML( $source, $options );
    }

    function getElementsByClassName ( $name ) {
        return $this->query( "//*[contains(@class, '{$name}')]" );
    }

    function query ( $query ) {
        $xpath = $this->xpath ? $this->xpath : new DOMXpath( $this );
        return $xpath->query( $query );
    }

    #[\ReturnTypeWillChange]
    function saveHTML ( $item = null ) {
        $content = parent::saveHTML( $item );
        if ( $content === false ) {
            return false; 
        }
        if (! $item ) {
            if ( PHP_VERSION >= 8.2 ) {
                $content = html_entity_decode( $content );
            } else {
                $content = mb_convert_encoding( $content, $this->text_encoding, 'HTML-ENTITIES' );
            }
        }
        return $content;
    }

    function querySelector ( $selector ) {
        $items = $this->query( new Translator( trim( $selector ) ) );
        if ( $items->length ) {
            return $items->item( 0 );
        }
        return null;
    }

    function querySelectorAll ( $selector ) {
        return $this->query( new Translator( trim( $selector ) ) );
    }

    public static function selector2xpath ( $selector ) {
        $selector = new Translator( trim( $selector ) );
        return (string) $selector;
    }
}

class PTDOMElement extends DOMElement {

    function __get ( $prop ) {
        if ( $prop === 'innerHTML' || $prop === 'outerHTML' ) {
            return $this->$prop();
        }
        if ( $prop === 'childrenElements' ) {
            return $this->getChildrenElements();
        }
        return null;
    }

    function __set ( $prop, $value ) {
        if ( $prop === 'innerHTML' ) {
            return $this->$prop( $value );
        }
    }

    function find ( $selector ) {
        $items = $this->ownerDocument->querySelectorAll( $selector );
        $children = $this->getChildrenElements();
        $elements = [];
        foreach ( $children as $child ) {
            foreach ( $items as $item ) {
                if ( $child === $item ) {
                    $elements[] = $child;
                    continue 2;
                }
            }
        }
        return new PTDOMNodeList( $elements );
    }

    function innerHTML ( $html = null ) {
        if ( is_string( $html ) ) {
            $element = $this;
            $fragment = $element->ownerDocument->createDocumentFragment();
            $fragment->appendXML( $html );
            while ( $element->hasChildNodes() ) {
                $element->removeChild( $element->firstChild );
            }
            return $element->appendChild( $fragment );
        }
        $innerHTML = ''; 
        $children  = $this->childNodes;
        foreach ( $children as $child ) { 
            $innerHTML .= $this->ownerDocument->saveHTML( $child );
        }
        return $innerHTML; 
    }

    function outerHTML () {
        $element = $this;
        $outerHTML = ''; 
        if ( $element->nodeType === XML_ELEMENT_NODE ) {
            $tagAttrs = [];
            foreach ( $element->attributes as $attr ) {
                $tagAttrs[] = $attr->nodeValue ?
                              $attr->nodeName . '="' . $attr->nodeValue . '"' : $attr->nodeName;
            }
            if (! empty( $tagAttrs ) ) {
                $startTag = '<' . $element->nodeName . ' ' . implode( ' ', $tagAttrs ) . '>';
            } else {
                $startTag = '<' . $element->nodeName . '>';
            }
            $endTag = '</' . $element->nodeName . '>';
        }
        $children  = $element->childNodes;
        if ( $children !== null ) {
            foreach ( $children as $child ) { 
                $outerHTML .= $element->ownerDocument->saveHTML( $child );
            }
        }
        $empty = ['br', 'hr', 'img'];
        if ( $element->nodeType == 1 ) {
            if (! $outerHTML && in_array( $element->nodeName, $empty ) ) {
                return str_replace( '>', ' />', $startTag );
            }
            $outerHTML = "{$startTag}{$outerHTML}{$endTag}";
        }
        return $outerHTML;
    }

    function getElementsByClassName ( $name ) {
        $childlenNodes = $this->getChildrenElements();
        $elements = [];
        foreach ( $childlenNodes as $child ) {
            if ( $class = $child->getAttribute( 'class' ) ) {
                $classes = preg_split( "/\s+/", trim( $class ) );
                if ( in_array( $name, $classes ) ) {
                    $elements[] = $child;
                }
            }
        }
        return new PTDOMNodeList( $elements );
    }

    function getChildrenElements ( $element = null, &$elements = [] ) {
        $element = $element ?? $this;
        $children = $element->childNodes;
        if ( $children->length ) {
            foreach ( $children as $child ) { 
                if ( $child->nodeType === 1 ) {
                    $elements[] = $child;
                    $this->getChildrenElements( $child, $elements );
                }
            }
        }
        return new PTDOMNodeList( $elements );
    }
}

class PTDOMNodeList extends stdClass {

    private $elements = [];
    private $count = 0;

    public function __construct ( $elements ) {
        $this->elements = $elements;
        $this->count = count( $elements );
        foreach ( $elements as $idx => $element ) {
            $this->$idx = $element;
        }
    }

    function __get ( $prop ) {
        if ( $prop === 'length' ) {
            return $this->count;
        }
    }

    function count () {
        return $this->count;
    }

    function item ( int $index ) {
        return $this->elements[ $index ] ?? null;
    }
}