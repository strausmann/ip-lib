<?php

namespace IPLib\Range;

use IPLib\Address\AddressInterface;
use IPLib\Address\IPv4;
use IPLib\Address\Type as AddressType;
use IPLib\Factory;

/**
 * Represents an address range in subnet format (eg CIDR).
 *
 * @example 127.0.0.1/32
 * @example ::/8
 */
class Subnet extends AbstractRange
{
    /**
     * Starting address of the range.
     *
     * @var \IPLib\Address\AddressInterface
     */
    protected $fromAddress;

    /**
     * Final address of the range.
     *
     * @var \IPLib\Address\AddressInterface
     */
    protected $toAddress;

    /**
     * Number of the same bits of the range.
     *
     * @var int
     */
    protected $networkPrefix;

    /**
     * The type of the range of this IP range.
     *
     * @var int|null
     */
    protected $rangeType;

    /**
     * The 6to4 address IPv6 address range.
     *
     * @var self|null
     */
    private static $sixToFour;

    /**
     * Initializes the instance.
     *
     * @param \IPLib\Address\AddressInterface $fromAddress
     * @param \IPLib\Address\AddressInterface $toAddress
     * @param int $networkPrefix
     *
     * @internal
     */
    public function __construct(AddressInterface $fromAddress, AddressInterface $toAddress, $networkPrefix)
    {
        $this->fromAddress = $fromAddress;
        $this->toAddress = $toAddress;
        $this->networkPrefix = $networkPrefix;
    }

    /**
     * {@inheritdoc}
     *
     * @see \IPLib\Range\RangeInterface::__toString()
     */
    public function __toString()
    {
        return $this->toString();
    }

    /**
     * Try get the range instance starting from its string representation.
     *
     * @param string|mixed $range
     * @param bool $supportNonDecimalIPv4 set to true to support parsing non decimal (that is, octal and hexadecimal) IPv4 addresses
     *
     * @return static|null
     */
    public static function fromString($range, $supportNonDecimalIPv4 = false)
    {
        if (!is_string($range)) {
            return null;
        }
        $parts = explode('/', $range);
        if (count($parts) !== 2) {
            return null;
        }
        $missingDots = 0;
        if ($supportNonDecimalIPv4 && strpos($parts[0], ':') === false) {
            $missingDots = 3 - substr_count($parts[0], '.');
            if ($missingDots > 0) {
                $parts[0] .= str_repeat('.0', $missingDots);
            }
        }
        $address = Factory::addressFromString($parts[0], true, true, $supportNonDecimalIPv4);
        if ($address === null) {
            return null;
        }
        if (!preg_match('/^[0-9]{1,9}$/', $parts[1])) {
            return null;
        }
        $networkPrefix = (int) $parts[1];
        $addressBytes = $address->getBytes();
        $totalBytes = count($addressBytes);
        $numDifferentBits = ($missingDots > 0 ? 4 - $missingDots : $totalBytes) * 8 - $networkPrefix;
        if ($numDifferentBits < 0) {
            return null;
        }
        $numSameBytes = $networkPrefix >> 3;
        $sameBytes = array_slice($addressBytes, 0, $numSameBytes);
        $differentBytesStart = ($totalBytes === $numSameBytes) ? array() : array_fill(0, $totalBytes - $numSameBytes, 0);
        $differentBytesEnd = ($totalBytes === $numSameBytes) ? array() : array_fill(0, $totalBytes - $numSameBytes, 255);
        $startSameBits = $networkPrefix % 8;
        if ($startSameBits !== 0) {
            $varyingByte = $addressBytes[$numSameBytes];
            $differentBytesStart[0] = $varyingByte & bindec(str_pad(str_repeat('1', $startSameBits), 8, '0', STR_PAD_RIGHT));
            $differentBytesEnd[0] = $differentBytesStart[0] + bindec(str_repeat('1', 8 - $startSameBits));
        }

        return new static(
            Factory::addressFromBytes(array_merge($sameBytes, $differentBytesStart)),
            Factory::addressFromBytes(array_merge($sameBytes, $differentBytesEnd)),
            $networkPrefix
        );
    }

    /**
     * {@inheritdoc}
     *
     * @see \IPLib\Range\RangeInterface::toString()
     */
    public function toString($long = false)
    {
        return $this->fromAddress->toString($long) . '/' . $this->networkPrefix;
    }

    /**
     * {@inheritdoc}
     *
     * @see \IPLib\Range\RangeInterface::getAddressType()
     */
    public function getAddressType()
    {
        return $this->fromAddress->getAddressType();
    }

    /**
     * {@inheritdoc}
     *
     * @see \IPLib\Range\RangeInterface::getStartAddress()
     */
    public function getStartAddress()
    {
        return $this->fromAddress;
    }

    /**
     * {@inheritdoc}
     *
     * @see \IPLib\Range\RangeInterface::getEndAddress()
     */
    public function getEndAddress()
    {
        return $this->toAddress;
    }

    /**
     * {@inheritdoc}
     *
     * @see \IPLib\Range\RangeInterface::getComparableStartString()
     */
    public function getComparableStartString()
    {
        return $this->fromAddress->getComparableString();
    }

    /**
     * {@inheritdoc}
     *
     * @see \IPLib\Range\RangeInterface::getComparableEndString()
     */
    public function getComparableEndString()
    {
        return $this->toAddress->getComparableString();
    }

    /**
     * {@inheritdoc}
     *
     * @see \IPLib\Range\RangeInterface::asSubnet()
     */
    public function asSubnet()
    {
        return $this;
    }

    /**
     * Get the pattern (asterisk) representation (if applicable) of this range.
     *
     * @return \IPLib\Range\Pattern|null return NULL if this range can't be represented by a pattern notation
     */
    public function asPattern()
    {
        $address = $this->getStartAddress();
        $networkPrefix = $this->getNetworkPrefix();
        switch ($address->getAddressType()) {
            case AddressType::T_IPv4:
                return $networkPrefix % 8 === 0 ? new Pattern($address, $address, 4 - $networkPrefix / 8) : null;
            case AddressType::T_IPv6:
                return $networkPrefix % 16 === 0 ? new Pattern($address, $address, 8 - $networkPrefix / 16) : null;
        }
    }

    /**
     * Get the 6to4 address IPv6 address range.
     *
     * @return self
     */
    public static function get6to4()
    {
        if (self::$sixToFour === null) {
            self::$sixToFour = self::fromString('2002::/16');
        }

        return self::$sixToFour;
    }

    /**
     * Get subnet prefix.
     *
     * @return int
     */
    public function getNetworkPrefix()
    {
        return $this->networkPrefix;
    }

    /**
     * {@inheritdoc}
     *
     * @see \IPLib\Range\RangeInterface::getSubnetMask()
     */
    public function getSubnetMask()
    {
        if ($this->getAddressType() !== AddressType::T_IPv4) {
            return null;
        }
        $bytes = array();
        $prefix = $this->getNetworkPrefix();
        while ($prefix >= 8) {
            $bytes[] = 255;
            $prefix -= 8;
        }
        if ($prefix !== 0) {
            $bytes[] = bindec(str_pad(str_repeat('1', $prefix), 8, '0'));
        }
        $bytes = array_pad($bytes, 4, 0);

        return IPv4::fromBytes($bytes);
    }

    /**
     * {@inheritdoc}
     *
     * @see \IPLib\Range\RangeInterface::getReverseDNSLookupName()
     */
    public function getReverseDNSLookupName()
    {
        switch ($this->getAddressType()) {
            case AddressType::T_IPv4:
                $unitSize = 8; // bytes
                $maxUnits = 4;
                $isHex = false;
                $rxUnit = '\d+';
                break;
            case AddressType::T_IPv6:
                $unitSize = 4; // nibbles
                $maxUnits = 32;
                $isHex = true;
                $rxUnit = '[0-9A-Fa-f]';
                break;
        }
        $totBits = $unitSize * $maxUnits;
        $prefixUnits = (int) ($this->networkPrefix / $unitSize);
        $extraBits = ($totBits - $this->networkPrefix) % $unitSize;
        if ($extraBits !== 0) {
            $prefixUnits += 1;
        }
        $numVariants = 1 << $extraBits;
        $result = array();
        $unitsToRemove = $maxUnits - $prefixUnits;
        $initialPointer = preg_replace("/^(({$rxUnit})\.){{$unitsToRemove}}/", '', $this->getStartAddress()->getReverseDNSLookupName());
        $chunks = explode('.', $initialPointer, 2);
        for ($index = 0; $index < $numVariants; $index++) {
            if ($index !== 0) {
                $chunks[0] = $isHex ? dechex(1 + hexdec($chunks[0])) : (string) (1 + (int) $chunks[0]);
            }
            $result[] = implode('.', $chunks);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * @see \IPLib\Range\RangeInterface::getSize()
     */
    public function getSize()
    {
        $fromAddress = $this->fromAddress;
        $maxPrefix = $fromAddress::getNumberOfBits();
        $prefix = $this->getNetworkPrefix();

        return pow(2, ($maxPrefix - $prefix));
    }
}
