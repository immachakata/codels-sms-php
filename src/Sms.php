<?php

namespace IsaacMachakata\CodelSms;

use IsaacMachakata\CodelSms\Exception\InvalidPhoneNumber;

/**
 * Represents an sms instance for the codel bulk sms service.
 * @author Isaac Machakata <hie@isaac.co.zw>
 */

class Sms
{
    private string $destination;
    private string $message;
    private ?string $reference;
    private ?int $timestamp;
    private ?string $validity;

    private function __construct(
        string $destination,
        string $message,
        ?string $reference,
        ?int $timestamp,
        ?string $validity
    ) {
        if (!empty(trim($destination))) {
            $this->destination = Utils::formatNumber($destination);
        } else {
            $this->destination = $destination;
        }
        $this->message = $message;
        $this->reference = $reference;
        $this->timestamp = $timestamp;
        $this->validity = $validity;
    }

    /**
     * Creates a new message instance and returns the back the data in an array formatted for the API.
     *
     * @param string $destination
     * @param string $message
     * @param string|null $reference
     * @param string|null $timestamp
     * @param string $validity
     * @return self
     */
    public static function new(string $destination, ?string $message = null, ?string $reference = null, ?string $timestamp = null, ?string $validity = ''): self
    {
        $finalDestination = $destination;
        $finalMessage = $message;

        if (is_null($message)) {
            $finalMessage = $destination;
            $finalDestination = "";
        }

        if (empty($timestamp)) {
            $timestamp = time();
        } elseif ($timestamp >= time() && $validity == '') {
            $validity = date('H:i', $timestamp);
        }
        if (empty($reference)) {
            $reference = uniqid();
        }
        if (empty($validity)) {
            $validity = '03:00';
        }

        if (!empty($finalDestination)) {
            $finalDestination = Utils::formatNumber($finalDestination);
        }

        return new self($finalDestination, $finalMessage, $reference, $timestamp, $validity);
    }

    /**
     * Sets the receivers phone number and returns a new Sms instance.
     *
     * @param string $destination
     * @throws InvalidPhoneNumber
     * @return self
     */
    public function setReceiver(string $destination): self
    {
        return new self(
            Utils::formatNumber($destination),
            $this->message,
            $this->reference,
            $this->timestamp,
            $this->validity
        );
    }

    /**
     * Returns the phone number of the receiver.
     *
     */
    public function getDestination(): string
    {
        return $this->destination;
    }

    /**
     * Prepares the variables into an array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'destination' => $this->destination,
            'messageText' => $this->message,
            'messageReference' => $this->reference,
            'messageDate' => date('YmdHis'),
            'messageValidity' => $this->validity,
            'sendDateTime' => date('H:i', $this->timestamp),
        ];
    }

    public function __toString(): string
    {
        return json_encode($this->toArray());
    }
}
