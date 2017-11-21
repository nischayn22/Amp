<?php

// Based on https://stackoverflow.com/a/4431560/1150075

class RecursiveDOMIterator implements RecursiveIterator{
    protected $_position;
    protected $_nodeList;
    public function __construct(DOMNode $domNode)
    {
        $this->_position = 0;
        $this->_nodeList = $domNode->childNodes;
    }
    public function getChildren() { return new self($this->current()); }
    public function key()         { return $this->_position; }
    public function next()        { $this->_position++; }
    public function rewind()      { $this->_position = 0; }
    public function valid()
    {
        return $this->_position < $this->_nodeList->length;
    }
    public function hasChildren()
    {
        return $this->current()->hasChildNodes();
    }
    public function current()
    {
        return $this->_nodeList->item($this->_position);
    }
}